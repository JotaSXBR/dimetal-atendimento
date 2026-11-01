<?php

namespace Tests\Feature\Bot;

use App\Enums\ClosedReason;
use App\Enums\MessageOrigin;
use App\Enums\TicketStatus;
use App\Jobs\SendTicketMessageJob;
use App\Models\BotSession;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\WorkflowVersion;
use App\Services\Bot\BotEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EngineCoreTest extends TestCase
{
    use RefreshDatabase;

    private Sector $vendas;

    private Sector $suporte;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->vendas = Sector::factory()->create(['name' => 'Vendas', 'menu_order' => 1]);
        $this->suporte = Sector::factory()->create(['name' => 'Suporte', 'menu_order' => 2]);
        $this->ticket = Ticket::factory()->create([
            'channel_id' => Channel::factory()->connected()->create()->id,
            'contact_id' => Contact::factory()->create(['name' => 'Maria'])->id,
            'status' => TicketStatus::PENDENTE,
        ]);
    }

    /**
     * @param  list<array{string, string, array<string, mixed>}>  $nodes
     * @param  list<array{string, string, string}>  $edges
     */
    private function version(array $nodes, array $edges): WorkflowVersion
    {
        return WorkflowVersion::factory()->create(['graph' => [
            'nodes' => array_map(fn (array $n): array => ['id' => $n[0], 'type' => $n[1], 'x' => 0, 'y' => 0, 'data' => $n[2]], $nodes),
            'edges' => array_map(fn (array $e): array => ['from' => $e[0], 'port' => $e[1], 'to' => $e[2]], $edges),
        ]]);
    }

    private function simple(): WorkflowVersion
    {
        return $this->version(
            [
                ['i', 'inicio', []],
                ['g', 'mensagem', ['texto' => 'Olá {{nome}}!']],
                ['m', 'menu_setores', ['texto' => 'Escolha']],
                ['f', 'mensagem', ['texto' => 'Anotado. Protocolo {{protocolo}}, setor {{setor}}.']],
                ['t', 'transferir', []],
            ],
            [['i', 'out', 'g'], ['g', 'out', 'm'], ['m', 'setor', 'f'], ['f', 'out', 't']],
        );
    }

    /** @return list<string> */
    private function botTexts(): array
    {
        return Message::query()->where('ticket_id', $this->ticket->id)->where('origin', MessageOrigin::BOT->value)->orderBy('id')->pluck('body')->all();
    }

    private function say(BotSession $session, string $text, string $type = 'text'): void
    {
        $message = Message::factory()->create(['ticket_id' => $this->ticket->id, 'type' => $type, 'body' => $text]);
        app(BotEngine::class)->receive($session->fresh() ?? $session, $message);
    }

    private function event(string $type): ?TicketEvent
    {
        return TicketEvent::query()->where('ticket_id', $this->ticket->id)->where('type', $type)->latest('id')->first();
    }

    public function test_fluxo_simples_saudacao_menu_de_setores_e_encaminhamento(): void
    {
        $session = app(BotEngine::class)->start($this->ticket, $this->simple());

        $this->assertSame(['Olá Maria!', "Escolha\n\n1 - Vendas\n2 - Suporte\n\nPara falar com um atendente digite #sair"], $this->botTexts());
        $this->assertSame('menu', $session->fresh()?->waiting);
        $this->assertNotNull($session->fresh()?->expires_at);
        Queue::assertPushed(SendTicketMessageJob::class, 2);

        $this->say($session, '2');

        $this->assertSame(
            'Anotado. Protocolo '.$this->ticket->protocol.', setor Suporte.',
            $this->botTexts()[2],
        );
        $fresh = $this->ticket->fresh();
        $this->assertSame(TicketStatus::AGUARDANDO, $fresh?->status);
        $this->assertSame($this->suporte->id, $fresh?->sector_id);
        $this->assertSame(BotSession::TRANSFERRED, $session->fresh()?->state);
        $this->assertSame('fluxo', $this->event('bot_handoff')?->payload['reason'] ?? null);
        $this->assertNotNull($this->event('bot_started'));
    }

    public function test_opcao_invalida_repete_duas_vezes_e_na_terceira_vai_a_fila_padrao(): void
    {
        $session = app(BotEngine::class)->start($this->ticket, $this->simple());

        $this->say($session, '9');
        $this->say($session, 'oi');
        $this->assertSame(TicketStatus::PENDENTE, $this->ticket->fresh()?->status);
        $this->assertStringContainsString('Opção inválida', $this->botTexts()[2]);
        $this->assertStringContainsString('1 - Vendas', $this->botTexts()[3]);

        $this->say($session, 'x');

        $fresh = $this->ticket->fresh();
        $this->assertSame(TicketStatus::AGUARDANDO, $fresh?->status);
        $this->assertSame($this->vendas->id, $fresh?->sector_id);
        $this->assertSame('erros', $this->event('bot_handoff')?->payload['reason'] ?? null);
        $texts = $this->botTexts();
        $this->assertStringContainsString($this->ticket->protocol, (string) end($texts));
    }

    public function test_audio_no_menu_conta_como_opcao_invalida(): void
    {
        $session = app(BotEngine::class)->start($this->ticket, $this->simple());

        $this->say($session, '', 'media');

        $this->assertSame(TicketStatus::PENDENTE, $this->ticket->fresh()?->status);
        $this->assertSame(1, $session->fresh()?->attempts);
        $this->assertStringContainsString('Opção inválida', $this->botTexts()[2]);
    }

    public function test_sair_em_qualquer_caixa_vai_a_fila_padrao(): void
    {
        $session = app(BotEngine::class)->start($this->ticket, $this->simple());

        $this->say($session, '  #SAIR ');

        $fresh = $this->ticket->fresh();
        $this->assertSame(TicketStatus::AGUARDANDO, $fresh?->status);
        $this->assertSame($this->vendas->id, $fresh?->sector_id);
        $this->assertSame('sair', $this->event('bot_handoff')?->payload['reason'] ?? null);
        $this->assertSame(BotSession::TRANSFERRED, $session->fresh()?->state);
    }

    public function test_sem_setor_no_menu_vai_para_aguardando_sem_setor(): void
    {
        Sector::query()->update(['status' => 'desativado']);

        $session = app(BotEngine::class)->start($this->ticket, $this->simple());

        $fresh = $this->ticket->fresh();
        $this->assertSame(TicketStatus::AGUARDANDO, $fresh?->status);
        $this->assertNull($fresh?->sector_id);
        $this->assertSame('erros', $this->event('bot_handoff')?->payload['reason'] ?? null);
        $this->assertSame(BotSession::TRANSFERRED, $session->fresh()?->state);
    }

    public function test_setor_desativado_depois_de_publicar_cai_na_fila_padrao(): void
    {
        $session = app(BotEngine::class)->start($this->ticket, $this->simple());
        $this->suporte->update(['status' => 'desativado']);

        $this->say($session, '2');

        $this->assertSame($this->vendas->id, $this->ticket->fresh()?->sector_id);
    }

    public function test_teto_de_passos_encerra_laco_e_encaminha(): void
    {
        $version = $this->version(
            [['i', 'inicio', []], ['a', 'mensagem', ['texto' => 'de novo']]],
            [['i', 'out', 'a'], ['a', 'out', 'a']],
        );

        app(BotEngine::class)->start($this->ticket, $version);

        $this->assertLessThanOrEqual(50, count($this->botTexts()) - 1);
        $this->assertSame(TicketStatus::AGUARDANDO, $this->ticket->fresh()?->status);
        $this->assertSame('erros', $this->event('bot_handoff')?->payload['reason'] ?? null);
    }

    public function test_encerrar_fecha_o_ticket_como_bot(): void
    {
        $version = $this->version(
            [['i', 'inicio', []], ['a', 'mensagem', ['texto' => 'Até logo']], ['e', 'encerrar', []]],
            [['i', 'out', 'a'], ['a', 'out', 'e']],
        );

        $session = app(BotEngine::class)->start($this->ticket, $version);

        $fresh = $this->ticket->fresh();
        $this->assertSame(TicketStatus::ENCERRADO, $fresh?->status);
        $this->assertSame(ClosedReason::BOT, $fresh?->closed_reason);
        $this->assertSame(BotSession::CLOSED, $session->fresh()?->state);
    }
}
