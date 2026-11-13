<?php

namespace Tests\Feature\Bot;

use App\Enums\MessageOrigin;
use App\Enums\NoteOrigin;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Events\TicketQueued;
use App\Models\BotSession;
use App\Models\BusinessHour;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Sector;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketNote;
use App\Models\User;
use App\Models\WorkflowVersion;
use App\Notifications\TicketAssigned;
use App\Services\Bot\BotEngine;
use App\Services\Inbox\SlaClock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @param  array<string, mixed>  $data */
    private function transfer(array $data): BotSession
    {
        return $this->start([['i', 'inicio', []], ['t', 'transferir', $data]], [['i', 'out', 't']]);
    }

    private function attendant(bool $active = true, bool $inVendas = true): User
    {
        $user = User::factory()->create(['role' => UserRole::ATENDENTE, 'active' => $active]);

        if ($inVendas) {
            $user->sectors()->attach(Sector::query()->where('name', 'Vendas')->firstOrFail());
        }

        return $user;
    }

    public function test_atribuir_a_assume_o_ticket_para_a_pessoa_do_setor(): void
    {
        Notification::fake();
        $user = $this->attendant();

        $this->transfer(['setor_id' => Sector::query()->firstOrFail()->id, 'atribuir_a' => $user->id]);

        $ticket = $this->ticket->fresh();
        $this->assertSame(TicketStatus::EM_ATENDIMENTO, $ticket?->status);
        $this->assertSame($user->id, $ticket?->assigned_user_id);
        $this->assertTrue(TicketEvent::query()->where('ticket_id', $ticket?->id)->where('type', 'claimed')->exists());
        Notification::assertSentTo($user, TicketAssigned::class);
    }

    /** @return array<string, array{string}> */
    public static function notAssignable(): array
    {
        return [
            'pessoa inativa' => ['inactive'],
            'pessoa de outro setor' => ['otherSector'],
            'pessoa que não existe' => ['missing'],
        ];
    }

    #[DataProvider('notAssignable')]
    public function test_atribuir_a_que_nao_pode_deixa_so_na_fila(string $case): void
    {
        $id = match ($case) {
            'inactive' => $this->attendant(active: false)->id,
            'otherSector' => $this->attendant(inVendas: false)->id,
            default => 999999,
        };

        $this->transfer(['setor_id' => Sector::query()->firstOrFail()->id, 'atribuir_a' => $id]);

        $ticket = $this->ticket->fresh();
        $this->assertSame(TicketStatus::AGUARDANDO, $ticket?->status);
        $this->assertNull($ticket?->assigned_user_id);
        $this->assertSame(BotSession::TRANSFERRED, BotSession::query()->where('ticket_id', $ticket?->id)->value('state'));
    }

    public function test_atribuir_a_nao_toca_o_som_de_fila_do_setor(): void
    {
        Event::fake([TicketQueued::class]);
        $this->attendant(); // outra pessoa do setor, que ouviria o som da fila
        $assignee = $this->attendant();

        $this->transfer(['setor_id' => Sector::query()->firstOrFail()->id, 'atribuir_a' => $assignee->id]);

        $this->assertSame($assignee->id, $this->ticket->fresh()?->assigned_user_id);
        Event::assertNotDispatched(TicketQueued::class);
    }

    public function test_atribuir_a_que_nao_pode_ainda_avisa_a_fila(): void
    {
        Event::fake([TicketQueued::class]);
        $this->attendant(); // quem ouve a fila
        $inactive = $this->attendant(active: false);

        $this->transfer(['setor_id' => Sector::query()->firstOrFail()->id, 'atribuir_a' => $inactive->id]);

        Event::assertDispatched(TicketQueued::class, 1);
    }

    public function test_fila_geral_ignora_o_setor_e_deixa_o_ticket_sem_setor(): void
    {
        $this->transfer(['fila_geral' => true, 'setor_id' => Sector::query()->firstOrFail()->id]);

        $ticket = $this->ticket->fresh();
        $this->assertSame(TicketStatus::AGUARDANDO, $ticket?->status);
        $this->assertNull($ticket?->sector_id);
        $this->assertSame(BotSession::TRANSFERRED, BotSession::query()->where('ticket_id', $ticket?->id)->value('state'));
    }

    public function test_fila_geral_fora_do_expediente_so_comeca_a_contar_o_sla_na_abertura(): void
    {
        foreach ([1, 2, 3, 4, 5] as $weekday) {
            BusinessHour::create(['weekday' => $weekday, 'opens_at' => '08:00', 'closes_at' => '18:00']);
        }

        // Domingo à noite, em São Paulo: fechado até segunda 08:00.
        $this->travelTo(CarbonImmutable::parse('2026-11-08 22:00', 'America/Sao_Paulo'));

        $this->transfer(['fila_geral' => true]);
        app(SlaClock::class)();

        $ticket = $this->ticket->fresh();
        $this->assertNull($ticket?->sector_id);
        $this->assertSame('2026-11-09 08:10:00', $ticket?->sla_due_at?->setTimezone('America/Sao_Paulo')->format('Y-m-d H:i:s'));
    }

    public function test_transferir_sem_atribuir_a_continua_igual(): void
    {
        $this->transfer(['setor_id' => Sector::query()->firstOrFail()->id]);

        $this->assertSame(TicketStatus::AGUARDANDO, $this->ticket->fresh()?->status);
        $this->assertNull($this->ticket->fresh()?->assigned_user_id);
    }

    /** @return array{list<array{string, string, array<string, mixed>}>, list<array{string, string, string}>} */
    private function branch(string $kind): array
    {
        return [
            [['i', 'inicio', []], ['c', 'condicao', ['kind' => $kind]], ['s', 'mensagem', ['texto' => 'SIM']], ['n', 'mensagem', ['texto' => 'NAO']], ['e', 'encerrar', []]],
            [['i', 'out', 'c'], ['c', 'true', 's'], ['c', 'false', 'n'], ['s', 'out', 'e'], ['n', 'out', 'e']],
        ];
    }

    /** @return array{list<array{string, string, array<string, mixed>}>, list<array{string, string, string}>} */
    private function branchOnTag(?int $tagId): array
    {
        [$nodes, $edges] = $this->branch('etiqueta');
        $nodes[1][2] = ['kind' => 'etiqueta', 'tag_id' => $tagId];

        return [$nodes, $edges];
    }

    public function test_condicao_etiqueta_do_contato_olha_o_contato(): void
    {
        $tag = Tag::factory()->contato()->create();
        $this->ticket->contact()->firstOrFail()->tags()->attach($tag);

        $this->start(...$this->branchOnTag($tag->id));

        $this->assertSame(['SIM'], $this->botTexts());
    }

    public function test_condicao_etiqueta_do_contato_sem_a_etiqueta_vai_por_nao(): void
    {
        $tag = Tag::factory()->contato()->create();

        $this->start(...$this->branchOnTag($tag->id));

        $this->assertSame(['NAO'], $this->botTexts());
    }

    public function test_condicao_etiqueta_da_conversa_olha_o_ticket_e_nao_o_contato(): void
    {
        $tag = Tag::factory()->create();
        $this->ticket->contact()->firstOrFail()->tags()->attach(Tag::factory()->contato()->create());
        $this->ticket->tags()->attach($tag);

        $this->start(...$this->branchOnTag($tag->id));

        $this->assertSame(['SIM'], $this->botTexts());
    }

    public function test_condicao_etiqueta_apagada_ou_sem_escolha_vai_por_nao(): void
    {
        $this->start(...$this->branchOnTag(99999));

        $this->assertSame(['NAO'], $this->botTexts());
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
