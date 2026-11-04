<?php

namespace Tests\Feature\Livewire;

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
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/** A barra "Respondendo a…" e o envio citado pela tela. */
class InboxReplyTest extends TestCase
{
    use RefreshDatabase;

    private const JPEG = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";

    private User $me;

    private Ticket $ticket;

    private Message $original;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('local');
        RateLimiter::clear('send-message:1');

        $this->me = User::factory()->create(['role' => UserRole::ATENDENTE, 'sign_messages' => false]);
        $this->ticket = Ticket::factory()->create([
            'channel_id' => Channel::factory()->connected()->create()->id,
            'contact_id' => Contact::factory()->create(['wa_id' => '5516997524950@c.us', 'name' => 'Maria'])->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            'assigned_user_id' => $this->me->id,
        ]);
        $this->original = Message::factory()->for($this->ticket)->create(['wa_message_id' => 'ORIG1', 'body' => 'Quanto custa a chapa?']);
    }

    private function inbox(): Testable
    {
        return Livewire::actingAs($this->me)->test(Inbox::class)->call('select', $this->ticket->id);
    }

    public function test_responder_mostra_a_barra_e_envia_citado_e_limpa(): void
    {
        $component = $this->inbox()->call('reply', $this->original->id);

        $component->assertSet('replyToId', $this->original->id)->assertSee('Respondendo a Maria')->assertSee('Quanto custa a chapa?');

        $component->set('draft', 'R$ 120')->call('send')->assertSet('replyToId', null)->assertSet('draft', '');

        $sent = Message::query()->where('direction', MessageDirection::OUT->value)->sole();
        $this->assertSame('ORIG1', $sent->quoted_wa_message_id);
        Queue::assertPushed(SendTicketMessageJob::class, 1);
    }

    public function test_barra_responder_a_foto_mostra_a_miniatura(): void
    {
        $photo = Message::factory()->for($this->ticket)->create(['wa_message_id' => 'PH1', 'type' => 'media', 'body' => '', 'media_mime' => 'image/jpeg', 'media_name' => 'foto.jpg', 'media_ref' => 'files/foto.jpg']);

        $html = $this->inbox()->call('reply', $photo->id)->assertSee('Respondendo a')->html();

        $this->assertMatchesRegularExpression('/<img[^>]*size-14[^>]*midia\/'.$photo->id.'[^>]*>|<img[^>]*midia\/'.$photo->id.'[^>]*size-14[^>]*>/', $html);
    }

    public function test_barra_responder_a_documento_mostra_o_nome(): void
    {
        $document = Message::factory()->for($this->ticket)->create(['wa_message_id' => 'DOC1', 'type' => 'media', 'body' => '', 'media_mime' => 'application/pdf', 'media_name' => 'orcamento.pdf', 'media_ref' => 'files/o.pdf']);

        $this->inbox()->call('reply', $document->id)->assertSee('Respondendo a')->assertSee('orcamento.pdf');
    }

    public function test_cancelar_tira_a_barra(): void
    {
        $this->inbox()->call('reply', $this->original->id)->call('cancelReply')->assertSet('replyToId', null)->assertDontSee('Respondendo a');
    }

    public function test_so_a_primeira_mensagem_do_lote_leva_a_citacao(): void
    {
        $component = $this->inbox();
        foreach (['a.jpg', 'b.jpg'] as $name) {
            $component->set('upload', UploadedFile::fake()->createWithContent($name, self::JPEG))->call('commitUpload', $this->ticket->id);
        }

        $component->call('reply', $this->original->id)->set('draft', 'Segue')->call('send');

        $sent = Message::query()->where('direction', MessageDirection::OUT->value)->orderBy('id')->get();
        $this->assertCount(2, $sent);
        $this->assertSame(['ORIG1', null], $sent->pluck('quoted_wa_message_id')->all());
    }

    public function test_responder_a_mensagem_de_outro_ticket_e_ignorado(): void
    {
        $other = Ticket::factory()->create([
            'channel_id' => $this->ticket->channel_id,
            'contact_id' => Contact::factory()->create()->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            'assigned_user_id' => $this->me->id,
        ]);
        $foreign = Message::factory()->for($other)->create(['wa_message_id' => 'OTHER1']);

        $this->inbox()->call('reply', $foreign->id)->assertSet('replyToId', null);
        $this->inbox()->call('reply', 999999)->assertSet('replyToId', null);
    }

    public function test_trocar_de_ticket_limpa_a_citacao(): void
    {
        $other = Ticket::factory()->create([
            'channel_id' => $this->ticket->channel_id,
            'contact_id' => Contact::factory()->create()->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            'assigned_user_id' => $this->me->id,
        ]);

        $this->inbox()->call('reply', $this->original->id)->call('select', $other->id)->assertSet('replyToId', null);
    }

    public function test_usuario_sem_reply_nao_cita(): void
    {
        $this->ticket->update(['status' => TicketStatus::ENCERRADO]);

        $this->inbox()->call('reply', $this->original->id)->assertForbidden();
    }
}
