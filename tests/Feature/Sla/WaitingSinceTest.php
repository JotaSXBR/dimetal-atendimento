<?php

namespace Tests\Feature\Sla;

use App\Actions\Inbox\ReassignTicket;
use App\Actions\Inbox\TransferTicket;
use App\Enums\MessageOrigin;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WahaEvent;
use App\Services\Inbox\IngestMessage;
use App\Services\Inbox\SendTicketMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WaitingSinceTest extends TestCase
{
    use RefreshDatabase;

    private const PEER = '5516997524950@c.us';

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
        $this->channel = Channel::factory()->connected()->create(['waha_session' => 'main']);
    }

    private function utc(string $time): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-30 '.$time, 'UTC');
    }

    private function ticket(array $override = []): Ticket
    {
        return Ticket::factory()->create(array_replace([
            'channel_id' => $this->channel->id,
            'contact_id' => Contact::factory()->create(['wa_id' => self::PEER])->id,
            'status' => TicketStatus::AGUARDANDO,
        ], $override));
    }

    /** @param  array<string, mixed>  $payload */
    private function ingest(array $payload): void
    {
        app(IngestMessage::class)(WahaEvent::create([
            'waha_event_id' => 'evt_'.uniqid(),
            'channel_id' => $this->channel->id,
            'event' => 'message',
            'session' => 'main',
            'payload' => ['event' => 'message', 'session' => 'main', 'payload' => $payload],
            'received_at' => now(),
        ]));
    }

    public function test_so_a_primeira_mensagem_sem_resposta_fixa_waiting_since(): void
    {
        $ticket = $this->ticket();

        $ticket->recordInbound($this->utc('10:00:00'));
        $ticket->recordInbound($this->utc('10:03:00'));

        $this->assertSame('2026-10-30 10:00:00', $ticket->waiting_since?->format('Y-m-d H:i:s'), 'o "oi?" do cliente não reinicia a espera');
        $this->assertSame('2026-10-30 10:03:00', $ticket->last_inbound_at?->format('Y-m-d H:i:s'));
    }

    public function test_resposta_humana_zera_a_espera_e_as_marcas(): void
    {
        $ticket = $this->ticket();
        $ticket->recordInbound($this->utc('10:00:00'));
        $ticket->forceFill(['sla_warn_at' => $this->utc('10:07:30'), 'sla_due_at' => $this->utc('10:10:00'), 'sla_warned_at' => $this->utc('10:08:00'), 'sla_breached_at' => $this->utc('10:11:00')])->save();

        $ticket->recordOutbound($this->utc('10:12:00'));

        foreach (['waiting_since', 'sla_warn_at', 'sla_due_at', 'sla_warned_at', 'sla_breached_at'] as $column) {
            $this->assertNull($ticket->{$column}, $column);
        }
        $this->assertNotNull($ticket->first_human_reply_at);
    }

    public function test_resposta_do_painel_entregue_zera(): void
    {
        Http::fake(['*' => Http::response(['id' => 'true_5516997524950@c.us_P1'], 201)]);
        $agent = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $ticket = $this->ticket(['status' => TicketStatus::EM_ATENDIMENTO, 'assigned_user_id' => $agent->id, 'waiting_since' => now()->subMinutes(5)]);

        app(SendTicketMessage::class)($ticket, $agent, 'Segue o orçamento');

        $this->assertNull($ticket->fresh()?->waiting_since);
    }

    public function test_resposta_pelo_celular_tambem_zera(): void
    {
        $this->ingest(['id' => 'false_5516997524950@c.us_A1', 'from' => self::PEER, 'fromMe' => false, 'body' => 'Quero um orcamento', 'timestamp' => 1_793_000_000, 'hasMedia' => false]);
        $ticket = Ticket::query()->sole();
        $this->assertNotNull($ticket->waiting_since);

        $this->ingest(['id' => 'true_5516997524950@c.us_B1', 'from' => '5516999990001@c.us', 'to' => self::PEER, 'fromMe' => true, 'body' => 'Segue o orcamento', 'timestamp' => 1_793_000_100, 'hasMedia' => false]);

        $this->assertNull($ticket->fresh()?->waiting_since);
    }

    public function test_resposta_do_bot_nao_zera(): void
    {
        Http::fake(['*' => Http::response(['id' => 'true_5516997524950@c.us_BOT'], 201)]);
        $ticket = $this->ticket(['waiting_since' => now()->subMinutes(5)]);
        $reply = app(SendTicketMessage::class)->create($ticket, null, 'Estamos fechados', MessageOrigin::BOT);

        app(SendTicketMessage::class)->deliver($reply);

        $this->assertNotNull($ticket->fresh()?->waiting_since, 'só resposta humana para o relógio');
    }

    public function test_resposta_atrasada_de_antes_da_espera_nao_para_o_relogio(): void
    {
        $ticket = $this->ticket();
        $ticket->recordInbound($this->utc('10:00:00'));
        $ticket->forceFill(['sla_due_at' => $this->utc('10:10:00')])->save();

        // Um eco tardio ou uma mensagem da caixa de saída, de antes de o cliente escrever.
        $ticket->recordOutbound($this->utc('09:59:00'));

        $this->assertSame('2026-10-30 10:00:00', $ticket->waiting_since?->format('Y-m-d H:i:s'));
        $this->assertNotNull($ticket->sla_due_at);
    }

    public function test_mensagem_atrasada_de_antes_da_resposta_nao_abre_espera(): void
    {
        $ticket = $this->ticket();
        $ticket->recordOutbound($this->utc('10:00:00'));

        $ticket->recordInbound($this->utc('09:59:00'));

        $this->assertNull($ticket->waiting_since, 'o cliente escreveu antes da resposta: ninguém espera');
    }

    public function test_nova_mensagem_depois_da_resposta_abre_nova_espera(): void
    {
        $ticket = $this->ticket();
        $ticket->recordInbound($this->utc('10:00:00'));
        $ticket->recordOutbound($this->utc('10:05:00'));

        $ticket->recordInbound($this->utc('10:10:00'));

        $this->assertSame('2026-10-30 10:10:00', $ticket->waiting_since?->format('Y-m-d H:i:s'));
    }

    public function test_transferir_e_reatribuir_nao_reiniciam_o_relogio(): void
    {
        $from = Sector::factory()->create();
        $to = Sector::factory()->create();
        $supervisor = User::factory()->create(['role' => UserRole::SUPERVISOR]);
        $agent = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $agent->sectors()->attach($to);
        $ticket = $this->ticket(['status' => TicketStatus::EM_ATENDIMENTO, 'sector_id' => $from->id, 'waiting_since' => $this->utc('10:00:00'), 'sla_due_at' => $this->utc('10:10:00')]);

        $this->assertTrue((new TransferTicket)($ticket, $supervisor, $to, ''));
        $this->assertTrue((new ReassignTicket)($ticket->fresh() ?? $ticket, $supervisor, $agent));

        $fresh = $ticket->fresh();
        $this->assertSame('2026-10-30 10:00:00', $fresh?->waiting_since?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-30 10:10:00', $fresh->sla_due_at?->format('Y-m-d H:i:s'));
    }

    public function test_o_instante_e_gravado_no_fuso_do_banco(): void
    {
        $ticket = $this->ticket();

        // 10h de São Paulo são 13h UTC.
        $ticket->recordInbound(CarbonImmutable::parse('2026-10-30 10:00:00', 'America/Sao_Paulo'));

        $this->assertSame('2026-10-30 13:00:00', $ticket->waiting_since?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-30 13:00:00', $ticket->last_inbound_at?->format('Y-m-d H:i:s'));
    }
}
