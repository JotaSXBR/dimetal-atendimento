<?php

namespace Tests\Feature\Bot;

use App\Actions\Inbox\CloseTicket;
use App\Enums\MessageOrigin;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Events\TicketQueued;
use App\Jobs\AdvanceBotSessionJob;
use App\Jobs\SendTicketMessageJob;
use App\Models\BotSession;
use App\Models\BusinessHour;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Models\WahaEvent;
use App\Models\WorkflowVersion;
use App\Services\Bot\BotEngine;
use App\Services\Bot\BotEntry;
use App\Services\Inbox\IngestMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IngestIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const PEER = '5516997524950@c.us';

    private Channel $channel;

    private Sector $vendas;

    private Sector $suporte;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
        Queue::fake();
        $this->channel = Channel::factory()->connected()->create(['waha_session' => 'main']);
        $this->vendas = Sector::factory()->create(['name' => 'Vendas', 'menu_order' => 1]);
        $this->suporte = Sector::factory()->create(['name' => 'Suporte', 'menu_order' => 2]);
    }

    private function activate(): WorkflowVersion
    {
        $version = WorkflowVersion::factory()->create(['graph' => [
            'nodes' => [
                ['id' => 'i', 'type' => 'inicio', 'x' => 0, 'y' => 0, 'data' => []],
                ['id' => 'g', 'type' => 'mensagem', 'x' => 0, 'y' => 0, 'data' => ['texto' => 'Olá!']],
                ['id' => 'm', 'type' => 'menu_setores', 'x' => 0, 'y' => 0, 'data' => ['texto' => 'Escolha']],
                ['id' => 'f', 'type' => 'mensagem', 'x' => 0, 'y' => 0, 'data' => ['texto' => 'Protocolo {{protocolo}}']],
                ['id' => 't', 'type' => 'transferir', 'x' => 0, 'y' => 0, 'data' => []],
            ],
            'edges' => [
                ['from' => 'i', 'port' => 'out', 'to' => 'g'], ['from' => 'g', 'port' => 'out', 'to' => 'm'],
                ['from' => 'm', 'port' => 'setor', 'to' => 'f'], ['from' => 'f', 'port' => 'out', 'to' => 't'],
            ],
        ]]);
        $this->channel->update(['workflow_id' => $version->workflow_id]);

        return $version;
    }

    /** @param  array<string, mixed>  $extra */
    private function ingest(string $id, string $body = 'oi', array $extra = []): ?Message
    {
        $payload = array_merge(['id' => $id, 'from' => self::PEER, 'fromMe' => false, 'body' => $body, 'timestamp' => now()->timestamp, 'hasMedia' => false, '_data' => ['Info' => ['PushName' => 'Maria']]], $extra);

        $event = WahaEvent::create([
            'waha_event_id' => 'evt_'.uniqid(),
            'channel_id' => $this->channel->id,
            'event' => 'message',
            'session' => 'main',
            'payload' => ['event' => 'message', 'session' => 'main', 'payload' => $payload],
            'received_at' => now(),
        ]);

        return app(IngestMessage::class)($event);
    }

    /** Roda, na ordem, os jobs do bot que ficaram na fila falsa. */
    private function runBotJobs(): void
    {
        $jobs = Queue::pushed(AdvanceBotSessionJob::class)->all();
        Queue::fake();

        foreach ($jobs as $job) {
            app()->call([$job, 'handle']);
        }
    }

    private function ticket(): Ticket
    {
        return Ticket::query()->firstOrFail();
    }

    /** @return list<string> */
    private function botTexts(): array
    {
        return Message::query()->where('origin', MessageOrigin::BOT->value)->orderBy('id')->pluck('body')->all();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_canal_sem_workflow_continua_nascendo_aguardando(): void
    {
        $this->ingest('false_A1');

        $this->assertSame(TicketStatus::AGUARDANDO, $this->ticket()->status);
        $this->assertSame(0, BotSession::query()->count());
        Queue::assertNotPushed(AdvanceBotSessionJob::class);
    }

    public function test_canal_com_workflow_abre_pendente_e_so_entra_na_fila_ao_transferir(): void
    {
        Event::fake([TicketQueued::class]);
        User::factory()->create(['role' => UserRole::SUPERVISOR]);
        $this->activate();

        $this->ingest('false_A1');

        $ticket = $this->ticket();
        $this->assertSame(TicketStatus::PENDENTE, $ticket->status);
        $this->assertNotNull($ticket->protocol);
        Event::assertNotDispatched(TicketQueued::class);
        Queue::assertPushed(AdvanceBotSessionJob::class, 1);

        $this->runBotJobs();
        $this->assertSame(['Olá!', "Escolha\n\n1 - Vendas\n2 - Suporte\n\nPara falar com um atendente digite #sair"], $this->botTexts());

        $this->travel(5)->seconds();
        $this->ingest('false_A2', '1', ['timestamp' => now()->timestamp]);
        $this->runBotJobs();

        $fresh = $ticket->fresh();
        $this->assertSame(TicketStatus::AGUARDANDO, $fresh?->status);
        $this->assertSame($this->vendas->id, $fresh?->sector_id);
        $this->assertEqualsWithDelta(now()->timestamp, $fresh?->waiting_since?->timestamp, 5);
        Event::assertDispatched(TicketQueued::class);
    }

    public function test_webhook_repetido_nao_reexecuta_o_bot(): void
    {
        $this->activate();

        $this->ingest('false_A1');
        $this->ingest('false_A1');

        Queue::assertPushed(AdvanceBotSessionJob::class, 1);
        $this->assertSame(1, Message::query()->where('origin', MessageOrigin::CONTATO->value)->count());
    }

    public function test_duas_mensagens_seguidas_geram_um_menu_so(): void
    {
        $this->activate();

        $this->ingest('false_A1', 'oi');
        $this->ingest('false_A2', 'oi');
        $this->travel(5)->seconds();
        $this->runBotJobs();

        $this->assertCount(2, $this->botTexts(), 'saudação + menu, sem aviso de opção inválida');
        $this->assertSame(0, BotSession::query()->firstOrFail()->attempts);
        $this->assertSame(TicketStatus::PENDENTE, $this->ticket()->status);
    }

    public function test_mensagem_antiga_reprocessada_nao_inicia_o_bot(): void
    {
        $this->activate();

        $this->ingest('false_OLD', 'oi', ['timestamp' => now()->subHour()->timestamp]);

        $this->assertSame(TicketStatus::AGUARDANDO, $this->ticket()->status);
        Queue::assertNotPushed(AdvanceBotSessionJob::class);
    }

    public function test_mensagem_do_celular_encerra_o_bot_e_o_ticket_segue_em_atendimento(): void
    {
        $this->activate();
        $this->ingest('false_A1');
        $this->runBotJobs();

        $this->ingest('true_H1', 'Já te atendo por aqui', ['fromMe' => true, 'from' => '5516999990001@c.us', 'to' => self::PEER]);

        $this->assertSame(TicketStatus::EM_ATENDIMENTO, $this->ticket()->status);
        $this->assertSame(BotSession::ABANDONED, BotSession::query()->firstOrFail()->state);
        $this->assertNotNull(TicketEvent::query()->where('type', 'bot_abandoned')->first());
    }

    public function test_supervisor_que_encerra_o_ticket_pendente_fecha_a_sessao(): void
    {
        $this->activate();
        $this->ingest('false_A1');
        $this->runBotJobs();

        (new CloseTicket)($this->ticket(), User::factory()->create());

        $this->assertSame(BotSession::CLOSED, BotSession::query()->firstOrFail()->state);
    }

    public function test_resposta_automatica_de_fora_do_horario_nao_dispara_em_canal_com_workflow(): void
    {
        foreach ([1, 2, 3, 4, 5] as $weekday) {
            BusinessHour::create(['weekday' => $weekday, 'opens_at' => '08:00', 'closes_at' => '12:00']);
        }
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-30 22:00', 'America/Sao_Paulo'));
        $this->activate();

        $this->ingest('false_A1');
        $this->runBotJobs();

        $this->assertNull(TicketEvent::query()->where('type', 'after_hours_reply')->first());
        $this->assertSame('Olá!', $this->botTexts()[0]);
    }

    public function test_workflow_desativado_antes_do_job_libera_o_ticket_para_a_fila(): void
    {
        $this->activate();
        $this->ingest('false_A1');
        $this->channel->update(['workflow_id' => null]);

        $this->runBotJobs();

        $this->assertSame(TicketStatus::AGUARDANDO, $this->ticket()->status);
        $this->assertSame('sem_workflow', TicketEvent::query()->where('type', 'bot_handoff')->firstOrFail()->payload['reason']);
    }

    public function test_bot_entry_ignora_ticket_que_nao_esta_pendente(): void
    {
        $ticket = Ticket::factory()->create(['channel_id' => $this->channel->id, 'status' => TicketStatus::AGUARDANDO]);
        $message = Message::factory()->create(['ticket_id' => $ticket->id]);

        app(BotEntry::class)->handle($message);

        Queue::assertNotPushed(AdvanceBotSessionJob::class);
        $this->assertInstanceOf(BotEngine::class, app(BotEngine::class));
        Queue::assertNotPushed(SendTicketMessageJob::class);
    }
}
