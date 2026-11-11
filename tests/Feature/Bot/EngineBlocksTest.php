<?php

namespace Tests\Feature\Bot;

use App\Enums\MessageOrigin;
use App\Enums\NoteOrigin;
use App\Enums\TicketStatus;
use App\Models\BotSession;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\WorkflowVersion;
use App\Services\Bot\BotEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Os blocos e validações da fatia 4a, pelo motor real. */
class EngineBlocksTest extends TestCase
{
    use RefreshDatabase;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Sector::factory()->create(['name' => 'Vendas', 'menu_order' => 1]);
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
    private function start(array $nodes, array $edges): BotSession
    {
        $version = WorkflowVersion::factory()->create(['graph' => [
            'nodes' => array_map(fn (array $n): array => ['id' => $n[0], 'type' => $n[1], 'x' => 0, 'y' => 0, 'data' => $n[2]], $nodes),
            'edges' => array_map(fn (array $e): array => ['from' => $e[0], 'port' => $e[1], 'to' => $e[2]], $edges),
        ]]);

        return app(BotEngine::class)->start($this->ticket, $version);
    }

    private function say(BotSession $session, string $text): void
    {
        $message = Message::factory()->create(['ticket_id' => $this->ticket->id, 'type' => 'text', 'body' => $text]);
        app(BotEngine::class)->receive($session->fresh() ?? $session, $message);
    }

    /** @return list<string> */
    private function botTexts(): array
    {
        return Message::query()->where('ticket_id', $this->ticket->id)->where('origin', MessageOrigin::BOT->value)->orderBy('id')->pluck('body')->all();
    }

    /** @return array{list<array{string, string, array<string, mixed>}>, list<array{string, string, string}>} */
    private function askCnpj(): array
    {
        return [
            [
                ['i', 'inicio', []],
                ['p', 'pergunta', ['texto' => 'Seu CNPJ?', 'variavel' => 'cnpj', 'validacao' => 'cnpj', 'invalido' => 'CNPJ inválido.']],
                ['n', 'mensagem', ['texto' => 'Recebi {{cnpj}}.']],
                ['e', 'encerrar', []],
            ],
            [['i', 'out', 'p'], ['p', 'out', 'n'], ['n', 'out', 'e']],
        ];
    }

    public function test_pergunta_cnpj_guarda_formatado(): void
    {
        $session = $this->start(...$this->askCnpj());

        $this->say($session, '11222333000181');

        $this->assertSame('Recebi 11.222.333/0001-81.', $this->botTexts()[1]);
    }

    public function test_pergunta_cnpj_invalido_repete_e_na_terceira_vai_a_fila(): void
    {
        $session = $this->start(...$this->askCnpj());

        $this->say($session, '11222333000180');
        $this->assertSame('CNPJ inválido.', $this->botTexts()[1]);
        $this->assertSame(TicketStatus::PENDENTE, $this->ticket->fresh()?->status);

        $this->say($session, 'abc');
        $this->say($session, '1');

        $this->assertSame(TicketStatus::AGUARDANDO, $this->ticket->fresh()?->status);
        $this->assertNotContains('Recebi 1.', $this->botTexts());
    }

    public function test_pergunta_sem_validacao_nova_continua_igual(): void
    {
        $session = $this->start(
            [['i', 'inicio', []], ['p', 'pergunta', ['texto' => 'Qtd?', 'variavel' => 'qtd', 'validacao' => 'numero']], ['n', 'mensagem', ['texto' => 'Qtd {{qtd}}.']], ['e', 'encerrar', []]],
            [['i', 'out', 'p'], ['p', 'out', 'n'], ['n', 'out', 'e']],
        );

        $this->say($session, 'doze');
        $this->assertNotContains('Qtd doze.', $this->botTexts());

        $this->say($session, '12');
        $this->assertContains('Qtd 12.', $this->botTexts());
    }

    public function test_nota_grava_origem_bot_sem_autor_com_as_variaveis(): void
    {
        $version = WorkflowVersion::factory()->create(['graph' => [
            'nodes' => [
                ['id' => 'i', 'type' => 'inicio', 'x' => 0, 'y' => 0, 'data' => []],
                ['id' => 'p', 'type' => 'pergunta', 'x' => 0, 'y' => 0, 'data' => ['texto' => 'CNPJ?', 'variavel' => 'cnpj', 'validacao' => 'cnpj']],
                ['id' => 'n', 'type' => 'nota', 'x' => 0, 'y' => 0, 'data' => ['texto' => 'CNPJ {{cnpj}} e [{{inexistente}}]']],
                ['id' => 'e', 'type' => 'encerrar', 'x' => 0, 'y' => 0, 'data' => []],
            ],
            'edges' => [['from' => 'i', 'port' => 'out', 'to' => 'p'], ['from' => 'p', 'port' => 'out', 'to' => 'n'], ['from' => 'n', 'port' => 'out', 'to' => 'e']],
        ]]);
        $session = app(BotEngine::class)->start($this->ticket, $version);

        $this->say($session, '11222333000181');

        $note = TicketNote::query()->where('ticket_id', $this->ticket->id)->sole();
        $this->assertSame(NoteOrigin::BOT, $note->origin);
        $this->assertNull($note->user_id);
        $this->assertSame('CNPJ 11.222.333/0001-81 e []', $note->body);
    }

    /** @return array{list<array{string, string, array<string, mixed>}>, list<array{string, string, string}>} */
    private function branch(string $kind): array
    {
        return [
            [['i', 'inicio', []], ['c', 'condicao', ['kind' => $kind]], ['s', 'mensagem', ['texto' => 'SIM']], ['n', 'mensagem', ['texto' => 'NAO']], ['e', 'encerrar', []]],
            [['i', 'out', 'c'], ['c', 'true', 's'], ['c', 'false', 'n'], ['s', 'out', 'e'], ['n', 'out', 'e']],
        ];
    }

    public function test_condicao_nome_segue_pelo_nome_do_contato(): void
    {
        $this->start(...$this->branch('nome'));
        $this->assertSame(['SIM'], $this->botTexts());
    }

    public function test_condicao_nome_sem_nome_vai_por_nao(): void
    {
        $this->ticket->contact()->firstOrFail()->forceFill(['name' => ''])->save();

        $this->start(...$this->branch('nome'));

        $this->assertSame(['NAO'], $this->botTexts());
    }

    public function test_condicao_retorno_sem_ticket_anterior_vai_por_nao(): void
    {
        $this->start(...$this->branch('retorno'));

        $this->assertSame(['NAO'], $this->botTexts());
    }

    public function test_condicao_retorno_com_ticket_anterior_do_contato_vai_por_sim(): void
    {
        Ticket::factory()->create(['channel_id' => $this->ticket->channel_id, 'contact_id' => $this->ticket->contact_id, 'status' => TicketStatus::ENCERRADO]);

        $this->start(...$this->branch('retorno'));

        $this->assertSame(['SIM'], $this->botTexts());
    }
}
