<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/** A barra de mensagem do Inbox: abas em linha própria e, embaixo, uma linha só de ferramentas, campo e mic/Enviar. */
class InboxComposerLayoutTest extends TestCase
{
    use RefreshDatabase;

    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";

    private User $me;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('local');

        $this->me = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $this->ticket = Ticket::factory()->create([
            'channel_id' => Channel::factory()->connected()->create()->id,
            'contact_id' => Contact::factory()->create()->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            'assigned_user_id' => $this->me->id,
        ]);
    }

    private function inbox(): Testable
    {
        return Livewire::actingAs($this->me)->test(Inbox::class)->call('select', $this->ticket->id);
    }

    /** @param  list<string>  $needles */
    private function assertInOrder(string $html, array $needles): void
    {
        $last = -1;

        foreach ($needles as $needle) {
            $at = strpos($html, $needle);
            $this->assertNotFalse($at, "falta {$needle}");
            $this->assertGreaterThan($last, $at, "{$needle} fora de ordem");
            $last = $at;
        }
    }

    public function test_as_abas_ficam_em_linha_propria_acima_das_ferramentas(): void
    {
        $html = $this->inbox()->html();

        $this->assertInOrder($html, ['data-testid="composer-modes"', 'data-testid="composer-tools"']);
        $this->assertStringNotContainsString('@max-xl:basis-full', $html, 'as abas não disputam mais a linha do campo');
    }

    public function test_a_linha_de_ferramentas_tem_emoji_anexo_respostas_campo_e_mic_nessa_ordem(): void
    {
        $html = $this->inbox()->html();

        $this->assertInOrder($html, ['data-testid="composer-tools"', 'aria-label="Emoji"', 'aria-label="Anexar arquivo"', 'aria-label="Respostas rápidas"', 'aria-label="Mensagem"', 'aria-label="Gravar áudio"', 'aria-label="Enviar"']);
    }

    public function test_sem_arquivo_o_mic_e_o_enviar_alternam_por_filled(): void
    {
        $html = $this->inbox()->html();

        $this->assertMatchesRegularExpression('/<button[^>]*aria-label="Gravar áudio"[^>]*x-show="[^"]*!filled[^"]*"|<button[^>]*x-show="[^"]*!filled[^"]*"[^>]*aria-label="Gravar áudio"/', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*aria-label="Enviar"[^>]*x-show="mode === \'reply\' && filled"|<button[^>]*x-show="mode === \'reply\' && filled"[^>]*aria-label="Enviar"/', $html);
    }

    public function test_com_arquivo_na_bandeja_so_o_enviar_aparece_e_nao_o_mic(): void
    {
        $component = $this->inbox()
            ->set('upload', UploadedFile::fake()->createWithContent('foto.jpg', self::JPEG))
            ->call('commitUpload', $this->ticket->id);

        $html = $component->html();

        $this->assertStringContainsString('aria-label="Enviar"', $html);
        $this->assertStringNotContainsString('aria-label="Gravar áudio"', $html);
    }

    /** @return string a tag `<button ...>` que traz o aria-label pedido */
    private function buttonTag(string $html, string $label): string
    {
        $this->assertSame(1, preg_match('/<button[^>]*aria-label="'.preg_quote($label, '/').'"[^>]*>/', $html, $m), "falta o botão {$label}");

        return $m[0];
    }

    public function test_em_tela_estreita_um_botao_mais_abre_as_respostas_rapidas(): void
    {
        $html = $this->inbox()->html();

        $this->assertStringContainsString('@max-sm:hidden', $this->buttonTag($html, 'Respostas rápidas'));
        $mais = $this->buttonTag($html, 'Mais ações do compositor');
        $this->assertStringContainsString('@sm:hidden', $mais);
        $this->assertStringContainsString('toggleQuickReplies', $mais);
    }

    public function test_modo_nota_esconde_anexo_e_mic_e_mantem_emoji_e_respostas(): void
    {
        $html = $this->inbox()->html();

        $this->assertStringContainsString("x-show=\"mode === 'reply'\"", $this->buttonTag($html, 'Anexar arquivo'));
        $this->assertStringContainsString("mode === 'reply'", $this->buttonTag($html, 'Gravar áudio'));
        $this->assertStringNotContainsString('x-show', $this->buttonTag($html, 'Emoji'), 'o emoji vale nos dois modos');
        $this->assertStringNotContainsString('x-show', $this->buttonTag($html, 'Respostas rápidas'), 'as respostas valem nos dois modos');
        $this->assertStringContainsString('Salvar nota', $html);
    }

    public function test_o_campo_nao_tem_largura_minima_que_o_espreme(): void
    {
        $html = $this->inbox()->html();

        $this->assertStringNotContainsString('min-w-28', $html);
        $this->assertStringContainsString('min-w-0 flex-1', $html);
    }
}
