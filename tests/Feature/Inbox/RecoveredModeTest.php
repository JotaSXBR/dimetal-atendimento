<?php

namespace Tests\Feature\Inbox;

use App\Enums\DeliveryStatus;
use App\Enums\MessageOrigin;
use App\Enums\TicketStatus;
use App\Events\MessageCreated;
use App\Events\TicketQueued;
use App\Events\TicketUpdated;
use App\Jobs\AdvanceBotSessionJob;
use App\Jobs\ResolveMessageMediaJob;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WahaEvent;
use App\Models\WorkflowVersion;
use App\Services\Inbox\IngestMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** A mensagem recuperada de uma queda entra pelo mesmo caminho da ao vivo, sem os efeitos de "mensagem nova". */
class RecoveredModeTest extends TestCase
{
    use RefreshDatabase;

    private const PEER = '5516997524950@c.us';

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        Queue::fake();
        $this->channel = Channel::factory()->connected()->create(['waha_session' => 'main']);
        User::factory()->create();
    }

    private function withWorkflow(): void
    {
        $version = WorkflowVersion::factory()->create(['graph' => [
            'nodes' => [
                ['id' => 'i', 'type' => 'inicio', 'x' => 0, 'y' => 0, 'data' => []],
                ['id' => 'g', 'type' => 'mensagem', 'x' => 0, 'y' => 0, 'data' => ['texto' => 'Olá!']],
            ],
            'edges' => [['from' => 'i', 'port' => 'out', 'to' => 'g']],
        ]]);
        $this->channel->update(['workflow_id' => $version->workflow_id]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function ingest(string $id, int $secondsAgo, array $extra = [], string $mode = 'recovered', bool $fromMe = false): ?Message
    {
        $payload = array_merge($fromMe
            ? ['id' => 'true_'.self::PEER.'_'.$id, 'from' => '5516999990001@c.us', 'to' => self::PEER, 'fromMe' => true]
            : ['id' => 'false_'.self::PEER.'_'.$id, 'from' => self::PEER, 'fromMe' => false, '_data' => ['Info' => ['PushName' => 'Maria']]],
            ['body' => 'texto', 'timestamp' => now()->subSeconds($secondsAgo)->timestamp, 'hasMedia' => false],
            $extra);

        $event = WahaEvent::create([
            'waha_event_id' => $mode.':'.$id,
            'channel_id' => $this->channel->id,
            'event' => 'message',
            'session' => 'main',
            'payload' => ['event' => 'message', 'session' => 'main', 'payload' => $payload, 'mode' => $mode],
            'received_at' => now(),
        ]);

        return app(IngestMessage::class)($event);
    }

    public function test_recuperada_em_ticket_pendente_nao_avanca_o_bot(): void
    {
        $this->withWorkflow();
        $this->ingest('AAA1', 0, [], 'live');
        $this->assertSame(TicketStatus::PENDENTE, Ticket::query()->sole()->status);
        Queue::fake();

        $this->ingest('AAA2', 7200);

        Queue::assertNotPushed(AdvanceBotSessionJob::class);
    }

    public function test_resposta_ao_vivo_atrasada_ainda_avanca_o_bot(): void
    {
        $this->withWorkflow();
        $this->ingest('LATE1', 0, [], 'live');
        Queue::fake();

        $this->ingest('LATE2', 600, [], 'live');

        Queue::assertPushed(AdvanceBotSessionJob::class);
    }

    public function test_recuperada_nao_dispara_message_created_mas_atualiza_o_ticket(): void
    {
        Event::fake([MessageCreated::class, TicketUpdated::class]);

        $this->ingest('MC1', 7200);

        Event::assertNotDispatched(MessageCreated::class);
        Event::assertDispatched(TicketUpdated::class);
    }

    public function test_ao_vivo_dispara_message_created(): void
    {
        Event::fake([MessageCreated::class]);

        $this->ingest('MC2', 0, [], 'live');

        Event::assertDispatched(MessageCreated::class);
    }

    public function test_recuperada_sem_ticket_abre_aguardando_sem_som_e_sem_resposta_automatica(): void
    {
        Event::fake([TicketQueued::class]);
        $this->withWorkflow();

        $message = $this->ingest('BBB1', 7200);

        $ticket = Ticket::query()->sole();
        $this->assertSame(TicketStatus::AGUARDANDO, $ticket->status);
        Event::assertNotDispatched(TicketQueued::class);
        $this->assertSame(0, Message::query()->where('origin', MessageOrigin::BOT->value)->count());
        $this->assertSame(now()->subSeconds(7200)->timestamp, $message?->sent_at->getTimestamp());
    }

    public function test_ao_vivo_continua_tocando_o_som_de_fila(): void
    {
        Event::fake([TicketQueued::class]);

        $this->ingest('CCC1', 0, [], 'live');

        Event::assertDispatched(TicketQueued::class);
    }

    public function test_recuperada_conta_a_espera_desde_a_hora_real(): void
    {
        $this->ingest('DDD1', 10800);

        $this->assertSame(now()->subSeconds(10800)->timestamp, Ticket::query()->sole()->waiting_since?->getTimestamp());
    }

    public function test_resposta_do_celular_recuperada_depois_do_cliente_encerra_a_espera(): void
    {
        $this->ingest('EEE1', 10800);
        $this->ingest('EEE2', 7200, [], 'recovered', true);

        $this->assertNull(Ticket::query()->sole()->waiting_since);
    }

    public function test_recuperada_do_celular_usa_o_ack(): void
    {
        $lida = $this->ingest('FFF1', 3600, ['ack' => 3], 'recovered', true);
        $semAck = $this->ingest('FFF2', 3500, [], 'recovered', true);

        $this->assertSame(DeliveryStatus::READ, $lida?->delivery_status);
        $this->assertSame(DeliveryStatus::SENT, $semAck?->delivery_status);
    }

    public function test_midia_recuperada_vai_para_a_fila_history_e_a_ao_vivo_para_inbound(): void
    {
        $media = ['hasMedia' => true, 'body' => '', 'media' => ['filename' => 'a.jpg', 'mimetype' => 'image/jpeg', 'size' => 10]];

        $this->ingest('GGG1', 3600, $media);
        $this->ingest('GGG2', 0, $media, 'live');

        $queues = Queue::pushed(ResolveMessageMediaJob::class)->map(fn (ResolveMessageMediaJob $job) => $job->queue)->values()->all();
        $this->assertSame(['history', 'inbound'], $queues);
    }
}
