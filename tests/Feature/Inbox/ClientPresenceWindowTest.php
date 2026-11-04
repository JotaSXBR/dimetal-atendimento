<?php

namespace Tests\Feature\Inbox;

use App\Enums\TicketStatus;
use App\Jobs\GoOfflineJob;
use App\Jobs\SubscribeClientPresenceJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WahaEvent;
use App\Services\Inbox\IngestMessage;
use App\Services\Inbox\Presence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** A mensagem de entrada ao vivo abre a janela `online` (a mesma do bot) e assina a presença do chat. */
class ClientPresenceWindowTest extends TestCase
{
    use RefreshDatabase;

    private const PEER = '5516997524950@c.us';

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
        $this->freezeTime();
        Queue::fake();
        User::factory()->create();
        $this->channel = Channel::factory()->connected()->create(['waha_session' => 'main']);
    }

    private function ingest(string $id, string $mode = 'live', bool $fromMe = false): void
    {
        $payload = $fromMe
            ? ['id' => 'true_'.self::PEER.'_'.$id, 'from' => '5516999990001@c.us', 'to' => self::PEER, 'fromMe' => true]
            : ['id' => 'false_'.self::PEER.'_'.$id, 'from' => self::PEER, 'fromMe' => false, '_data' => ['Info' => ['PushName' => 'Maria']]];

        $event = WahaEvent::create([
            'waha_event_id' => $mode.':'.$id,
            'channel_id' => $this->channel->id,
            'event' => 'message',
            'session' => 'main',
            'payload' => ['event' => 'message', 'session' => 'main', 'payload' => array_merge($payload, ['body' => 'oi', 'timestamp' => now()->timestamp, 'hasMedia' => false]), 'mode' => $mode],
            'received_at' => now(),
        ]);

        app(IngestMessage::class)($event);
    }

    private function openTicket(): Ticket
    {
        return Ticket::factory()
            ->for(Contact::factory()->create(['wa_id' => self::PEER]))
            ->for($this->channel)
            ->create(['status' => TicketStatus::AGUARDANDO]);
    }

    public function test_entrada_ao_vivo_despacha_a_assinatura_para_o_ticket(): void
    {
        $this->ingest('LIVE1');

        $ticket = Ticket::query()->sole();
        Queue::assertPushed(SubscribeClientPresenceJob::class, fn (SubscribeClientPresenceJob $job): bool => $job->ticketId === $ticket->id);
    }

    public function test_so_a_entrada_ao_vivo_do_cliente_abre_a_janela(): void
    {
        $this->ingest('REC1', 'recovered');
        $this->ingest('IMP1', 'imported');
        $this->ingest('OUT1', 'live', fromMe: true);

        Queue::assertNotPushed(SubscribeClientPresenceJob::class);
    }

    public function test_job_garante_online_assina_e_agenda_offline_em_60s(): void
    {
        Http::fake(['*' => Http::response([], 201)]);
        $ticket = $this->openTicket();

        app()->call([new SubscribeClientPresenceJob($ticket->id), 'handle']);

        Http::assertSentInOrder([
            fn (Request $r) => str_ends_with($r->url(), '/api/main/presence') && $r['presence'] === 'online',
            fn (Request $r) => str_ends_with($r->url(), '/api/main/presence/'.self::PEER.'/subscribe'),
        ]);
        Queue::assertPushed(GoOfflineJob::class, fn (GoOfflineJob $job): bool => now()->addSeconds(60)->equalTo($job->delay));
    }

    public function test_janela_reaproveita_o_online_ja_aberto_pelo_bot(): void
    {
        Http::fake(['*' => Http::response([], 201)]);
        $ticket = $this->openTicket();
        Cache::put('presence-online:'.$this->channel->id, 1, 300);

        app()->call([new SubscribeClientPresenceJob($ticket->id), 'handle']);

        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/api/main/presence'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/subscribe'));
    }

    public function test_humano_ativo_adia_o_offline_da_janela(): void
    {
        Http::fake(['*' => Http::response([], 201)]);
        $ticket = $this->openTicket();
        app(Presence::class)->humanActive($this->channel, self::PEER);

        app()->call([new SubscribeClientPresenceJob($ticket->id), 'handle']);
        $last = Queue::pushed(GoOfflineJob::class)->last();
        app()->call([$last, 'handle']);

        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/api/main/presence') && $r['presence'] === 'offline');
    }

    public function test_canal_desconectado_nao_assina(): void
    {
        Http::fake();
        $this->channel->update(['status' => 'STOPPED']);
        $ticket = $this->openTicket();

        app()->call([new SubscribeClientPresenceJob($ticket->id), 'handle']);

        Http::assertNothingSent();
    }

    public function test_falha_do_waha_na_assinatura_nao_estoura_o_job(): void
    {
        Http::fake(['*/subscribe' => Http::response(['message' => 'boom'], 500), '*' => Http::response([], 201)]);
        Log::spy();
        $ticket = $this->openTicket();

        app()->call([new SubscribeClientPresenceJob($ticket->id), 'handle']);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'assinar presença'));
    }
}
