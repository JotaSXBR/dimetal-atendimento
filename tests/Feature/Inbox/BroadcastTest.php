<?php

namespace Tests\Feature\Inbox;

use App\Actions\Inbox\ClaimTicket;
use App\Actions\Inbox\CloseTicket;
use App\Enums\TicketStatus;
use App\Events\MessageCreated;
use App\Events\MessageStatusChanged;
use App\Events\TicketUpdated;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WahaEvent;
use App\Services\Inbox\ApplyAck;
use App\Services\Inbox\IngestMessage;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class BroadcastTest extends TestCase
{
    use RefreshDatabase;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = Channel::factory()->connected()->create(['waha_session' => 'main']);
    }

    private function wahaEvent(string $event, array $payload): WahaEvent
    {
        return WahaEvent::create([
            'waha_event_id' => 'evt_'.uniqid(),
            'channel_id' => $this->channel->id,
            'event' => $event,
            'session' => 'main',
            'payload' => ['event' => $event, 'session' => 'main', 'payload' => $payload],
            'received_at' => now(),
        ]);
    }

    public function test_mensagem_recebida_dispara_message_created_e_ticket_updated(): void
    {
        Event::fake([MessageCreated::class, TicketUpdated::class]);

        app(IngestMessage::class)($this->wahaEvent('message', [
            'id' => 'false_5516997524950@c.us_ABC',
            'from' => '5516997524950@c.us',
            'fromMe' => false,
            'body' => 'Oi',
            'timestamp' => 1_793_000_000,
        ]));

        Event::assertDispatched(MessageCreated::class);
        Event::assertDispatched(TicketUpdated::class);
    }

    public function test_ack_dispara_message_status_changed(): void
    {
        Event::fake([MessageStatusChanged::class]);
        $ticket = Ticket::factory()->for($this->channel)->create();
        Message::factory()->for($ticket)->create(['wa_message_id' => 'W1', 'delivery_status' => 'sent']);

        app(ApplyAck::class)($this->wahaEvent('message.ack', ['id' => 'W1', 'ack' => 3]));

        Event::assertDispatched(MessageStatusChanged::class);
    }

    public function test_assumir_e_encerrar_disparam_ticket_updated(): void
    {
        Event::fake([TicketUpdated::class]);
        $ticket = Ticket::factory()->for($this->channel)->create();
        $user = User::factory()->create();

        (new ClaimTicket)($ticket, $user);
        (new CloseTicket)($ticket, $user);

        Event::assertDispatchedTimes(TicketUpdated::class, 2);
        $this->assertSame(TicketStatus::ENCERRADO, $ticket->status);
    }

    public function test_payload_do_evento_nao_contem_texto(): void
    {
        $ticket = Ticket::factory()->for($this->channel)->create(['assigned_user_id' => User::factory()]);
        $message = Message::factory()->for($ticket)->create(['body' => 'segredo do cliente']);

        $event = new MessageCreated($message->load('ticket'));

        $this->assertSame(
            ['ticket_id' => $ticket->id, 'message_id' => $message->id, 'type' => 'message.created'],
            $event->broadcastWith(),
        );
        $this->assertEquals(
            [new PrivateChannel('inbox'), new PrivateChannel('users.'.$ticket->assigned_user_id)],
            array_values($event->broadcastOn()),
        );
    }

    public function test_reverb_so_aceita_a_origem_do_app_por_padrao(): void
    {
        $origins = config('reverb.apps.apps.0.allowed_origins');

        $this->assertNotContains('*', $origins);
        $this->assertSame([parse_url((string) config('app.url'), PHP_URL_HOST)], $origins);
    }

    public function test_autorizacao_dos_canais(): void
    {
        // O phpunit usa o broadcaster `null`, que aprova qualquer canal; aqui precisa o driver real.
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'k',
            'broadcasting.connections.reverb.secret' => 's',
            'broadcasting.connections.reverb.app_id' => '1',
        ]);
        Broadcast::purge();
        require base_path('routes/channels.php');

        $a = User::factory()->create();
        $b = User::factory()->create();
        $inativo = User::factory()->create(['active' => false]);

        $this->actingAs($a)->postJson('/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => 'private-inbox'])->assertOk()->assertJsonStructure(['auth']);
        $this->actingAs($a)->postJson('/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => 'private-users.'.$a->id])->assertOk();
        $this->actingAs($a)->postJson('/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => 'private-users.'.$b->id])->assertForbidden();
        $this->actingAs($inativo)->postJson('/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => 'private-inbox'])->assertForbidden();
    }
}
