<?php

namespace Tests\Feature\Bot;

use App\Enums\MessageOrigin;
use App\Enums\TicketStatus;
use App\Models\BotSession;
use App\Models\BusinessHour;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Sector;
use App\Models\Tag;
use App\Models\Ticket;
use App\Models\WorkflowVersion;
use App\Services\Bot\BotEngine;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class EngineExtraNodesTest extends TestCase
{
    use RefreshDatabase;

    private Ticket $ticket;

    private Contact $contact;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
        Queue::fake();
        Sleep::fake();
        Sector::factory()->create(['name' => 'Vendas', 'menu_order' => 1]);
        $this->contact = Contact::factory()->create(['name' => 'Maria', 'wa_id' => '5516997524950@c.us']);
        $this->ticket = Ticket::factory()->create([
            'channel_id' => Channel::factory()->connected()->create(['waha_session' => 'main'])->id,
            'contact_id' => $this->contact->id,
            'status' => TicketStatus::PENDENTE,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * Fluxo de uma linha: Início → blocos (ligados pela porta `out`, salvo `$ports`) → Transferir.
     *
     * @param  list<array{string, string, array<string, mixed>}>  $nodes  os blocos do meio, na ordem
     * @param  list<array{string, string, string}>  $extraEdges
     */
    private function line(array $nodes, array $extraEdges = []): WorkflowVersion
    {
        $all = [['i', 'inicio', []], ...$nodes, ['t', 'transferir', []]];
        $edges = $extraEdges;

        for ($k = 0; $k < count($all) - 1; $k++) {
            if (! in_array($all[$k][1], ['condicao'], true)) {
                $edges[] = [$all[$k][0], $all[$k][1] === 'pergunta' ? 'out' : 'out', $all[$k + 1][0]];
            }
        }

        return WorkflowVersion::factory()->create(['graph' => [
            'nodes' => array_map(fn (array $n): array => ['id' => $n[0], 'type' => $n[1], 'x' => 0, 'y' => 0, 'data' => $n[2]], $all),
            'edges' => array_map(fn (array $e): array => ['from' => $e[0], 'port' => $e[1], 'to' => $e[2]], $edges),
        ]]);
    }

    private function answer(BotSession $session, string $text): void
    {
        $message = Message::factory()->create(['ticket_id' => $this->ticket->id, 'body' => $text]);
        app(BotEngine::class)->receive($session->fresh() ?? $session, $message);
    }

    /** @return list<string> */
    private function botTexts(): array
    {
        return Message::query()->where('ticket_id', $this->ticket->id)->where('origin', MessageOrigin::BOT->value)->orderBy('id')->pluck('body')->all();
    }

    public function test_pergunta_de_email_repete_e_depois_guarda_a_resposta_na_variavel(): void
    {
        $version = $this->line([
            ['p', 'pergunta', ['texto' => 'Qual o seu e-mail?', 'variavel' => 'email', 'validacao' => 'email']],
            ['f', 'mensagem', ['texto' => 'Obrigado, {{email}}']],
        ]);

        $session = app(BotEngine::class)->start($this->ticket, $version);
        $this->assertSame('input', $session->fresh()?->waiting);

        $this->answer($session, 'isso nao e email');
        $this->assertSame(TicketStatus::PENDENTE, $this->ticket->fresh()?->status);
        $this->assertSame(['Qual o seu e-mail?', 'Resposta inválida. Tente de novo.', 'Qual o seu e-mail?'], $this->botTexts());

        $this->answer($session, 'maria@exemplo.com');

        $this->assertSame('Obrigado, maria@exemplo.com', $this->botTexts()[3]);
        $this->assertSame(TicketStatus::AGUARDANDO, $this->ticket->fresh()?->status);
    }

    public function test_pergunta_invalida_tres_vezes_vai_a_fila_padrao_e_sair_funciona_nela(): void
    {
        $version = $this->line([['p', 'pergunta', ['texto' => 'Número do pedido?', 'variavel' => 'pedido', 'validacao' => 'numero']]]);
        $session = app(BotEngine::class)->start($this->ticket, $version);

        $this->answer($session, 'abc');
        $this->answer($session, 'xyz');
        $this->answer($session, '12a');

        $this->assertSame(TicketStatus::AGUARDANDO, $this->ticket->fresh()?->status);

        $other = Ticket::factory()->create(['channel_id' => $this->ticket->channel_id, 'status' => TicketStatus::PENDENTE]);
        $this->ticket = $other;
        $second = app(BotEngine::class)->start($other, $version);
        $this->answer($second, '#sair');
        $this->assertSame(TicketStatus::AGUARDANDO, $other->fresh()?->status);
    }

    public function test_pergunta_de_numero_aceita_so_digitos(): void
    {
        $version = $this->line([['p', 'pergunta', ['texto' => 'Pedido?', 'variavel' => 'pedido', 'validacao' => 'numero']], ['f', 'mensagem', ['texto' => 'Pedido {{pedido}}']]]);
        $session = app(BotEngine::class)->start($this->ticket, $version);

        $this->answer($session, '12345');

        $this->assertSame('Pedido 12345', $this->botTexts()[1]);
    }

    public function test_condicao_de_horario_escolhe_o_caminho(): void
    {
        foreach ([1, 2, 3, 4, 5] as $weekday) {
            BusinessHour::create(['weekday' => $weekday, 'opens_at' => '08:00', 'closes_at' => '18:00']);
        }
        $version = WorkflowVersion::factory()->create(['graph' => [
            'nodes' => [
                ['id' => 'i', 'type' => 'inicio', 'data' => []],
                ['id' => 'c', 'type' => 'condicao', 'data' => ['kind' => 'horario']],
                ['id' => 'a', 'type' => 'mensagem', 'data' => ['texto' => 'Aberto']],
                ['id' => 'f', 'type' => 'mensagem', 'data' => ['texto' => 'Fechado']],
                ['id' => 't', 'type' => 'transferir', 'data' => []],
            ],
            'edges' => [
                ['from' => 'i', 'port' => 'out', 'to' => 'c'], ['from' => 'c', 'port' => 'true', 'to' => 'a'], ['from' => 'c', 'port' => 'false', 'to' => 'f'],
                ['from' => 'a', 'port' => 'out', 'to' => 't'], ['from' => 'f', 'port' => 'out', 'to' => 't'],
            ],
        ]]);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-30 10:00', 'America/Sao_Paulo'));
        app(BotEngine::class)->start($this->ticket, $version);
        $this->assertSame(['Aberto'], $this->botTexts());

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-30 22:00', 'America/Sao_Paulo'));
        $other = Ticket::factory()->create(['channel_id' => $this->ticket->channel_id, 'status' => TicketStatus::PENDENTE]);
        $this->ticket = $other;
        app(BotEngine::class)->start($other, $version);
        $this->assertSame(['Fechado'], $this->botTexts());
    }

    public function test_condicao_de_variavel_compara_sem_diferenciar_maiusculas(): void
    {
        $graph = fn (string $op, string $valor): WorkflowVersion => WorkflowVersion::factory()->create(['graph' => [
            'nodes' => [
                ['id' => 'i', 'type' => 'inicio', 'data' => []],
                ['id' => 'p', 'type' => 'pergunta', 'data' => ['texto' => 'Cidade?', 'variavel' => 'cidade', 'validacao' => 'texto']],
                ['id' => 'c', 'type' => 'condicao', 'data' => ['kind' => 'variavel', 'var' => 'cidade', 'op' => $op, 'valor' => $valor]],
                ['id' => 'y', 'type' => 'mensagem', 'data' => ['texto' => 'SIM']],
                ['id' => 'n', 'type' => 'mensagem', 'data' => ['texto' => 'NAO']],
                ['id' => 't', 'type' => 'transferir', 'data' => []],
            ],
            'edges' => [
                ['from' => 'i', 'port' => 'out', 'to' => 'p'], ['from' => 'p', 'port' => 'out', 'to' => 'c'],
                ['from' => 'c', 'port' => 'true', 'to' => 'y'], ['from' => 'c', 'port' => 'false', 'to' => 'n'],
                ['from' => 'y', 'port' => 'out', 'to' => 't'], ['from' => 'n', 'port' => 'out', 'to' => 't'],
            ],
        ]]);

        $session = app(BotEngine::class)->start($this->ticket, $graph('igual', 'franca'));
        $this->answer($session, 'FRANCA');
        $this->assertSame('SIM', $this->botTexts()[1]);

        $this->ticket = Ticket::factory()->create(['channel_id' => $this->ticket->channel_id, 'status' => TicketStatus::PENDENTE]);
        $second = app(BotEngine::class)->start($this->ticket, $graph('contem', 'rib'));
        $this->answer($second, 'Franca');
        $this->assertSame('NAO', $this->botTexts()[1]);
    }

    public function test_esperar_mostra_digitando_limita_os_segundos_e_nao_quebra_se_o_waha_falhar(): void
    {
        Http::fake(['*' => Http::response('erro', 500)]);
        $version = $this->line([['e', 'esperar', ['segundos' => 999]], ['f', 'mensagem', ['texto' => 'Pronto']]]);

        app(BotEngine::class)->start($this->ticket, $version);

        Sleep::assertSleptTimes(1);
        Sleep::assertSlept(fn ($duration): bool => $duration->totalSeconds === 30.0);
        $this->assertSame(['Pronto'], $this->botTexts());
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/startTyping'));
    }

    public function test_midia_ausente_nao_trava_o_fluxo_e_presente_vira_mensagem_do_bot(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('bot/catalogo.pdf', '%PDF-1.4 teste');
        $version = $this->line([
            ['m', 'midia', ['path' => 'bot/catalogo.pdf', 'legenda' => 'Nosso catálogo']],
            ['x', 'midia', ['path' => 'bot/sumiu.pdf']],
            ['f', 'mensagem', ['texto' => 'Fim']],
        ]);

        app(BotEngine::class)->start($this->ticket, $version);

        $media = Message::query()->where('ticket_id', $this->ticket->id)->where('type', 'media')->get();
        $this->assertCount(1, $media);
        $this->assertSame(MessageOrigin::BOT, $media[0]->origin);
        $this->assertSame('Nosso catálogo', $media[0]->body);
        $this->assertSame('bot/catalogo.pdf', $media[0]->local_path);
        $this->assertSame('catalogo.pdf', $media[0]->media_name);
        $this->assertSame(TicketStatus::AGUARDANDO, $this->ticket->fresh()?->status);
    }

    public function test_salvar_no_contato_grava_o_campo_e_deixa_o_contato_elegivel_ao_google(): void
    {
        $version = $this->line([
            ['p', 'pergunta', ['texto' => 'Empresa?', 'variavel' => 'empresa', 'validacao' => 'texto']],
            ['s', 'salvar_contato', ['campo' => 'company', 'variavel' => 'empresa']],
        ]);
        $session = app(BotEngine::class)->start($this->ticket, $version);

        $this->answer($session, 'Dimetal Ltda');

        $contact = $this->contact->fresh();
        $this->assertSame('Dimetal Ltda', $contact?->company);
        $this->assertNotNull($contact?->edited_at);
    }

    public function test_salvar_no_contato_ignora_campo_fora_da_lista(): void
    {
        $version = $this->line([
            ['p', 'pergunta', ['texto' => 'Algo?', 'variavel' => 'x', 'validacao' => 'texto']],
            ['s', 'salvar_contato', ['campo' => 'wa_id', 'variavel' => 'x']],
        ]);
        $session = app(BotEngine::class)->start($this->ticket, $version);

        $this->answer($session, 'hack');

        $this->assertSame('5516997524950@c.us', $this->contact->fresh()?->wa_id);
    }

    public function test_menu_de_opcoes_escritas_a_mao_leva_cada_numero_ao_seu_caminho(): void
    {
        $version = WorkflowVersion::factory()->create(['graph' => [
            'nodes' => [
                ['id' => 'i', 'type' => 'inicio', 'data' => []],
                ['id' => 'm', 'type' => 'menu', 'data' => ['texto' => 'Como podemos ajudar?', 'opcoes' => ['Orçamento', 'Segunda via']]],
                ['id' => 'a', 'type' => 'mensagem', 'data' => ['texto' => 'CAMINHO A']],
                ['id' => 'b', 'type' => 'mensagem', 'data' => ['texto' => 'CAMINHO B']],
                ['id' => 't', 'type' => 'transferir', 'data' => []],
            ],
            'edges' => [
                ['from' => 'i', 'port' => 'out', 'to' => 'm'], ['from' => 'm', 'port' => 'opt:1', 'to' => 'a'], ['from' => 'm', 'port' => 'opt:2', 'to' => 'b'],
                ['from' => 'a', 'port' => 'out', 'to' => 't'], ['from' => 'b', 'port' => 'out', 'to' => 't'],
            ],
        ]]);

        $session = app(BotEngine::class)->start($this->ticket, $version);
        $this->assertSame("Como podemos ajudar?\n\n1 - Orçamento\n2 - Segunda via\n\nPara falar com um atendente digite #sair", $this->botTexts()[0]);

        $this->answer($session, '2');

        $this->assertSame('CAMINHO B', $this->botTexts()[1]);
        $this->assertSame(TicketStatus::AGUARDANDO, $this->ticket->fresh()?->status);
    }

    public function test_menu_com_numero_fora_da_lista_e_invalido(): void
    {
        $version = WorkflowVersion::factory()->create(['graph' => [
            'nodes' => [
                ['id' => 'i', 'type' => 'inicio', 'data' => []],
                ['id' => 'm', 'type' => 'menu', 'data' => ['texto' => 'Escolha', 'opcoes' => ['Só uma'], 'invalido' => 'Número inexistente']],
                ['id' => 't', 'type' => 'transferir', 'data' => []],
            ],
            'edges' => [['from' => 'i', 'port' => 'out', 'to' => 'm'], ['from' => 'm', 'port' => 'opt:1', 'to' => 't']],
        ]]);
        $session = app(BotEngine::class)->start($this->ticket, $version);

        $this->answer($session, '2');

        $this->assertSame(TicketStatus::PENDENTE, $this->ticket->fresh()?->status);
        $this->assertSame('Número inexistente', $this->botTexts()[1]);
    }

    public function test_etiquetar_aplica_uma_vez_so(): void
    {
        $tag = Tag::factory()->create(['name' => 'Orçamento']);
        $version = $this->line([
            ['a', 'etiquetar', ['tag_id' => $tag->id]],
            ['b', 'etiquetar', ['tag_id' => $tag->id]],
        ]);

        app(BotEngine::class)->start($this->ticket, $version);

        $this->assertSame(['Orçamento'], $this->ticket->tags()->pluck('name')->all());
    }
}
