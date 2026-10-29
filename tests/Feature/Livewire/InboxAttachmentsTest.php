<?php

namespace Tests\Feature\Livewire;

use App\Enums\DeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Jobs\SendTicketMessageJob;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class InboxAttachmentsTest extends TestCase
{
    use RefreshDatabase;

    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";

    private const PDF = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

    private const MP3 = "ID3\x03\x00\x00\x00\x00\x00\x00\xFF\xFB\x90\x00\xFF\xFB\x90\x00\xFF\xFB\x90\x00\xFF\xFB\x90\x00";

    private User $me;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('local');
        RateLimiter::clear('send-message:1');

        $this->me = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $this->ticket = Ticket::factory()->create([
            'channel_id' => Channel::factory()->connected()->create()->id,
            'contact_id' => Contact::factory()->create()->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            'assigned_user_id' => $this->me->id,
        ]);
    }

    private function upload(string $name, string $bytes): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    /** @return Testable */
    private function inbox()
    {
        return Livewire::actingAs($this->me)->test(Inbox::class)->call('select', $this->ticket->id);
    }

    /** @param  array<string, string>  $files nome => bytes */
    private function attach(object $component, array $files): object
    {
        foreach ($files as $name => $bytes) {
            $component->set('upload', $this->upload($name, $bytes))->call('commitUpload');
        }

        return $component;
    }

    /** @return Collection<int, Message> */
    private function sent()
    {
        return Message::query()->where('direction', MessageDirection::OUT->value)->orderBy('id')->get();
    }

    public function test_tres_arquivos_viram_tres_mensagens_em_ordem_e_o_texto_e_a_legenda_do_primeiro(): void
    {
        $component = $this->inbox();
        $this->attach($component, ['foto.jpg' => self::JPEG, 'orcamento.pdf' => self::PDF, 'outra.jpg' => self::JPEG]);

        $component->set('draft', 'Segue o material')->call('send')->assertHasNoErrors();

        $messages = $this->sent();
        $this->assertCount(3, $messages);
        $this->assertSame(['foto.jpg', 'orcamento.pdf', 'outra.jpg'], $messages->pluck('media_name')->all());
        $this->assertSame(['Segue o material', '', ''], $messages->pluck('body')->all());
        $this->assertSame(['media', 'media', 'media'], $messages->pluck('type')->all());
        Queue::assertPushed(SendTicketMessageJob::class, 3);
        $component->assertSet('attachments', [])->assertSet('draft', '');
    }

    public function test_arquivo_recusado_mostra_o_erro_e_nao_impede_os_validos(): void
    {
        $component = $this->inbox();
        $this->attach($component, ['programa.exe' => "MZ\x90\x00\x03\x00\x00\x00"]);

        $component->assertHasErrors('attachments')->assertCount('attachments', 0);

        $this->attach($component, ['foto.jpg' => self::JPEG]);

        $component->assertCount('attachments', 1);
    }

    public function test_no_maximo_dez_arquivos_por_envio(): void
    {
        $component = $this->inbox();

        foreach (range(1, 10) as $i) {
            $this->attach($component, ["f{$i}.jpg" => self::JPEG]);
        }

        $this->attach($component, ['onze.jpg' => self::JPEG]);

        $component->assertCount('attachments', 10)->assertHasErrors('attachments');
    }

    public function test_remover_da_bandeja(): void
    {
        $component = $this->inbox();
        $this->attach($component, ['a.jpg' => self::JPEG, 'b.pdf' => self::PDF]);

        $component->call('removeAttachment', 0);

        $component->assertCount('attachments', 1);
        $this->assertSame('b.pdf', $component->get('attachments')[0]->getClientOriginalName());
    }

    public function test_sem_arquivo_o_texto_segue_como_mensagem_normal(): void
    {
        $this->inbox()->set('draft', 'so texto')->call('send')->assertHasNoErrors();

        $message = $this->sent()->sole();
        $this->assertSame('text', $message->type);
        $this->assertSame('so texto', $message->body);
    }

    public function test_enviar_sem_texto_e_sem_arquivo_pede_uma_mensagem(): void
    {
        $this->inbox()->call('send')->assertHasErrors('draft');

        $this->assertCount(0, $this->sent());
    }

    public function test_so_arquivos_sem_texto_enviam_sem_legenda(): void
    {
        $component = $this->inbox();
        $this->attach($component, ['foto.jpg' => self::JPEG]);

        $component->call('send')->assertHasNoErrors();

        $this->assertSame('', $this->sent()->sole()->body);
    }

    public function test_audio_no_primeiro_lugar_manda_o_texto_antes_em_mensagem_propria(): void
    {
        $component = $this->inbox();
        $this->attach($component, ['musica.mp3' => self::MP3, 'foto.jpg' => self::JPEG]);

        $component->set('draft', 'Ouça isto')->call('send')->assertHasNoErrors();

        $messages = $this->sent();
        $this->assertSame(['text', 'media', 'media'], $messages->pluck('type')->all());
        $this->assertSame(['Ouça isto', '', ''], $messages->pluck('body')->all());
    }

    public function test_legenda_acima_de_1024_caracteres_sai_como_texto_separado(): void
    {
        $component = $this->inbox();
        $this->attach($component, ['foto.jpg' => self::JPEG]);

        $component->set('draft', str_repeat('a', 1025))->call('send')->assertHasNoErrors();

        $this->assertSame(['text', 'media'], $this->sent()->pluck('type')->all());
        $this->assertSame('', $this->sent()->last()?->body);
    }

    public function test_cada_arquivo_conta_no_limite_de_envio_e_nada_sai_se_estourar(): void
    {
        config(['security.send_message_per_minute' => 2]);
        $component = $this->inbox();
        $this->attach($component, ['a.jpg' => self::JPEG, 'b.jpg' => self::JPEG, 'c.jpg' => self::JPEG]);

        $component->call('send')->assertHasErrors('draft');

        $this->assertCount(0, $this->sent());
        $component->assertCount('attachments', 3);
    }

    public function test_quem_nao_pode_responder_nao_anexa(): void
    {
        // Na fila (sem responsável) o atendente vê o ticket, mas só responde depois de assumir.
        $this->ticket->update(['assigned_user_id' => null, 'status' => TicketStatus::AGUARDANDO]);

        $component = Livewire::actingAs($this->me)->test(Inbox::class);
        $component->set('ticketId', $this->ticket->id);
        $component->set('upload', $this->upload('foto.jpg', self::JPEG))->call('commitUpload')->assertForbidden();
    }

    public function test_mensagem_de_anexo_aparece_como_enviada_depois_do_job(): void
    {
        $component = $this->inbox();
        $this->attach($component, ['foto.jpg' => self::JPEG]);
        $component->call('send');

        $this->assertNull($this->sent()->sole()->delivery_status);
        $this->assertNotSame(DeliveryStatus::FAILED, $this->sent()->sole()->delivery_status);
    }
}
