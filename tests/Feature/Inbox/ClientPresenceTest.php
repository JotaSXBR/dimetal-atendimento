<?php

namespace Tests\Feature\Inbox;

use App\Enums\TicketStatus;
use App\Events\ClientPresenceChanged;
use App\Jobs\HandleClientPresence;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WahaEvent;
use App\Services\Inbox\ClientPresence;
use App\Services\Inbox\IngestMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** `presence.update` do cliente vira estado em Redis (TTL) e evento no Reverb; nunca vai ao banco. */
class ClientPresenceTest extends TestCase
{
    use RefreshDatabase;

    private const PEER = '5516997524950@c.us';

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([ClientPresenceChanged::class]);
        $this->channel = Channel::factory()->connected()->create(['waha_session' => 'main']);
    }

    private function ticketFor(string $chat = self::PEER, ?Channel $channel = null, TicketStatus $status = TicketStatus::EM_ATENDIMENTO): Ticket
    {
        $contact = str_ends_with($chat, '@lid') ? Contact::factory()->create(['wa_id' => null, 'lid' => $chat]) : Contact::factory()->create(['wa_id' => $chat]);

        return Ticket::factory()->for($contact)->for($channel ?? $this->channel)->create(['status' => $status]);
    }

    /** @return array<string, mixed> */
    private function payload(string $presence, string $chat = self::PEER): array
    {
        return ['id' => $chat, 'presences' => [['participant' => $chat, 'lastKnownPresence' => $presence, 'lastSeen' => null]]];
    }

    /** @param array<mixed> $payload */
    private function apply(array $payload, ?Channel $channel = null): void
    {
        app()->call([new HandleClientPresence(($channel ?? $this->channel)->id, $payload), 'handle']);
    }

    public function test_digitando_vira_estado_no_redis_e_evento(): void
    {
        $ticket = $this->ticketFor();

        $this->apply($this->payload('typing'));

        $this->assertSame('digitando', Cache::get('client-presence:'.$ticket->id));
        Event::assertDispatched(ClientPresenceChanged::class, fn (ClientPresenceChanged $e): bool => $e->ticketId === $ticket->id && $e->state === 'digitando');
    }

    public function test_gravando_vira_estado_gravando(): void
    {
        $ticket = $this->ticketFor();

        $this->apply($this->payload('recording'));

        $this->assertSame('gravando', Cache::get('client-presence:'.$ticket->id));
        Event::assertDispatched(ClientPresenceChanged::class, fn (ClientPresenceChanged $e): bool => $e->state === 'gravando');
    }

    public function test_paused_online_e_offline_limpam_o_estado(): void
    {
        $ticket = $this->ticketFor();

        foreach (['paused', 'online', 'offline'] as $presence) {
            Cache::put('client-presence:'.$ticket->id, 'digitando', 60);
            $this->apply($this->payload($presence));

            $this->assertNull(Cache::get('client-presence:'.$ticket->id), $presence);
        }

        Event::assertDispatchedTimes(ClientPresenceChanged::class, 3);
        Event::assertDispatched(ClientPresenceChanged::class, fn (ClientPresenceChanged $e): bool => $e->state === 'parou');
    }

    public function test_parou_sem_estado_anterior_nao_transmite(): void
    {
        $this->ticketFor();

        $this->apply($this->payload('online'));

        Event::assertNotDispatched(ClientPresenceChanged::class);
    }

    public function test_contato_lid_resolve_o_ticket_pelo_lid(): void
    {
        $ticket = $this->ticketFor('1234567890@lid');

        $this->apply($this->payload('typing', '1234567890@lid'));

        $this->assertSame('digitando', Cache::get('client-presence:'.$ticket->id));
    }

    public function test_chat_sem_ticket_aberto_ou_de_tipo_desconhecido_nao_transmite(): void
    {
        $this->ticketFor(status: TicketStatus::ENCERRADO);
        Contact::factory()->create(['wa_id' => '5511911110000@c.us']);

        $this->apply($this->payload('typing'));
        $this->apply($this->payload('typing', '5511911110000@c.us'));
        $this->apply($this->payload('typing', '120363000000000000@newsletter'));
        $this->apply($this->payload('typing', 'status@broadcast'));
        $this->apply($this->payload('typing', '5599900000000@c.us'));

        Event::assertNotDispatched(ClientPresenceChanged::class);
        $this->assertSame([], app(ClientPresence::class)->states([1, 2, 3]));
    }

    public function test_dois_canais_com_o_mesmo_cliente_vao_ao_ticket_do_canal_certo(): void
    {
        $other = Channel::factory()->connected()->create(['waha_session' => 'second']);
        $contact = Contact::factory()->create(['wa_id' => self::PEER]);
        $mine = Ticket::factory()->for($contact)->for($this->channel)->create(['status' => TicketStatus::EM_ATENDIMENTO]);
        $theirs = Ticket::factory()->for($contact)->for($other)->create(['status' => TicketStatus::EM_ATENDIMENTO]);

        $this->apply($this->payload('typing'), $other);

        $this->assertSame('digitando', Cache::get('client-presence:'.$theirs->id));
        $this->assertNull(Cache::get('client-presence:'.$mine->id));
    }

    public function test_payload_em_formato_desconhecido_e_ignorado_sem_erro(): void
    {
        $ticket = $this->ticketFor();

        foreach ([[], ['id' => self::PEER], ['id' => self::PEER, 'presences' => 'x'], ['id' => self::PEER, 'presences' => [['lastKnownPresence' => 'dancando']]], ['presences' => []]] as $payload) {
            $this->apply($payload);
        }

        Event::assertNotDispatched(ClientPresenceChanged::class);
        $this->assertNull(Cache::get('client-presence:'.$ticket->id));
    }

    public function test_states_devolve_so_digitando_e_gravando(): void
    {
        $a = $this->ticketFor();
        $b = $this->ticketFor('5511922220000@c.us');
        Cache::put('client-presence:'.$a->id, 'digitando', 60);
        Cache::put('client-presence:'.$b->id, 'qualquer', 60);

        $this->assertSame([$a->id => 'digitando'], app(ClientPresence::class)->states([$a->id, $b->id]));
    }

    public function test_chegada_de_mensagem_do_cliente_limpa_o_estado(): void
    {
        Queue::fake();
        $ticket = $this->ticketFor(status: TicketStatus::AGUARDANDO);
        User::factory()->create();
        Cache::put('client-presence:'.$ticket->id, 'digitando', 60);

        $event = WahaEvent::create([
            'waha_event_id' => 'live:P1',
            'channel_id' => $this->channel->id,
            'event' => 'message',
            'session' => 'main',
            'payload' => ['event' => 'message', 'session' => 'main', 'mode' => 'live', 'payload' => [
                'id' => 'false_'.self::PEER.'_P1', 'from' => self::PEER, 'fromMe' => false, 'body' => 'oi', 'timestamp' => now()->timestamp, 'hasMedia' => false,
            ]],
            'received_at' => now(),
        ]);
        app(IngestMessage::class)($event);

        $this->assertNull(Cache::get('client-presence:'.$ticket->id));
        Event::assertDispatched(ClientPresenceChanged::class, fn (ClientPresenceChanged $e): bool => $e->ticketId === $ticket->id && $e->state === 'parou');
    }

    public function test_evento_leva_so_ids_e_estado_no_canal_inbox_pela_fila_realtime(): void
    {
        $event = new ClientPresenceChanged(7, 'gravando');

        $this->assertSame(['ticket_id' => 7, 'state' => 'gravando'], $event->broadcastWith());
        $this->assertSame('client.presence', $event->broadcastAs());
        $this->assertSame('realtime', $event->broadcastQueue());
        $this->assertSame(['private-inbox'], array_map(fn ($channel): string => $channel->name, $event->broadcastOn()));
    }

    public function test_nada_de_presenca_vai_ao_banco(): void
    {
        $this->ticketFor();

        $this->apply($this->payload('typing'));

        $this->assertSame(0, WahaEvent::count());
        $this->assertSame([], array_values(array_filter(Schema::getTableListing(), fn (string $table): bool => str_contains($table, 'presence'))));
    }
}
