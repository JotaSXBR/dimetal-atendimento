<?php

namespace Tests\Feature\Inbox;

use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Inbox\Quote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** O trecho que a citação guarda: quem escreveu, o tipo e até 200 caracteres. */
class QuoteSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private ?Channel $channel = null;

    /** @param array<string, mixed> $attributes */
    private function message(array $attributes = [], ?Contact $contact = null): Message
    {
        $ticket = Ticket::factory()->for($contact ?? Contact::factory()->create(['name' => 'Maria Souza']))->for($this->channel ??= Channel::factory()->create())->create();

        return Message::factory()->for($ticket)->create($attributes);
    }

    public function test_mensagem_do_cliente_usa_o_nome_do_contato_e_o_texto(): void
    {
        $snapshot = Quote::snapshot($this->message(['body' => 'Quero 10 chapas']));

        $this->assertSame(['author' => 'Maria Souza', 'type' => 'text', 'excerpt' => 'Quero 10 chapas'], $snapshot);
    }

    public function test_mensagem_do_painel_usa_o_nome_do_atendente_e_a_do_celular_usa_empresa(): void
    {
        $user = User::factory()->create(['name' => 'Ana Lima']);
        $painel = $this->message(['direction' => MessageDirection::OUT, 'origin' => MessageOrigin::PAINEL, 'author_user_id' => $user->id]);
        $celular = $this->message(['direction' => MessageDirection::OUT, 'origin' => MessageOrigin::FORA_DO_PAINEL, 'author_user_id' => null]);

        $this->assertSame('Ana Lima', Quote::snapshot($painel)['author']);
        $this->assertSame('Empresa', Quote::snapshot($celular)['author']);
    }

    public function test_trecho_acima_de_200_caracteres_e_cortado(): void
    {
        $snapshot = Quote::snapshot($this->message(['body' => str_repeat('a', 500)]));

        $this->assertSame(200, mb_strlen($snapshot['excerpt']));
    }

    public function test_midia_sem_texto_vira_o_nome_do_tipo(): void
    {
        $cases = ['image/jpeg' => 'Foto', 'image/webp' => 'Figurinha', 'audio/ogg' => 'Áudio', 'video/mp4' => 'Vídeo', 'application/pdf' => 'Arquivo'];

        foreach ($cases as $mime => $label) {
            $snapshot = Quote::snapshot($this->message(['type' => 'media', 'body' => '', 'media_mime' => $mime]));

            $this->assertSame($label, $snapshot['excerpt'], $mime);
            $this->assertSame('media', $snapshot['type']);
        }
    }

    public function test_midia_traz_o_id_local_o_tipo_e_o_nome(): void
    {
        $photo = $this->message(['type' => 'media', 'body' => '', 'media_mime' => 'image/jpeg', 'media_name' => 'foto.jpg']);
        $document = $this->message(['type' => 'media', 'body' => 'Segue', 'media_mime' => 'application/pdf', 'media_name' => 'orcamento.pdf']);

        $this->assertSame(['message_id' => $photo->id, 'kind' => 'image', 'name' => 'foto.jpg'], Quote::snapshot($photo)['media']);
        $this->assertSame(['message_id' => $document->id, 'kind' => 'document', 'name' => 'orcamento.pdf'], Quote::snapshot($document)['media']);
    }

    public function test_documento_sem_legenda_usa_o_nome_do_arquivo_como_trecho(): void
    {
        $named = $this->message(['type' => 'media', 'body' => '', 'media_mime' => 'application/pdf', 'media_name' => 'COTAÇÃO DIMETAL.docx']);
        $unnamed = $this->message(['type' => 'media', 'body' => '', 'media_mime' => 'application/pdf', 'media_name' => null]);
        $photo = $this->message(['type' => 'media', 'body' => '', 'media_mime' => 'image/jpeg', 'media_name' => 'foto.jpg']);

        $this->assertSame('COTAÇÃO DIMETAL.docx', Quote::snapshot($named)['excerpt']);
        $this->assertSame('Arquivo', Quote::snapshot($unnamed)['excerpt']);
        $this->assertSame('Foto', Quote::snapshot($photo)['excerpt'], 'foto segue com o rótulo; a miniatura é que a mostra');
    }

    public function test_texto_continua_sem_a_chave_media(): void
    {
        $this->assertArrayNotHasKey('media', Quote::snapshot($this->message(['body' => 'oi'])));
    }
}
