<?php

namespace Tests\Feature\Bot;

use App\Models\Sector;
use App\Models\Tag;
use App\Models\User;
use App\Services\Bot\Simulation\BotSimulator;
use App\Services\Bot\Simulation\SimulationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BotSimulatorTest extends TestCase
{
    use RefreshDatabase;

    private Sector $vendas;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendas = Sector::factory()->create(['name' => 'Vendas', 'menu_order' => 1]);
    }

    /**
     * @param  list<array{string, string, array<string, mixed>}>  $nodes
     * @param  list<array{string, string, string}>  $edges  [de, porta, para]
     * @return array<string, mixed>
     */
    private function graph(array $nodes, array $edges): array
    {
        return [
            'nodes' => array_map(fn (array $n): array => ['id' => $n[0], 'type' => $n[1], 'x' => 0, 'y' => 0, 'data' => $n[2]], $nodes),
            'edges' => array_map(fn (array $e): array => ['from' => $e[0], 'port' => $e[1], 'to' => $e[2]], $edges),
        ];
    }

    /** @return list<string> */
    private function texts(SimulationState $state, string $kind): array
    {
        return array_values(array_map(fn (array $l): string => $l['text'], array_filter($state->log, fn (array $l): bool => $l['kind'] === $kind)));
    }

    private function sim(): BotSimulator
    {
        return app(BotSimulator::class);
    }

    /** @return array<string, mixed> Início → Menu(Vendas) → Transferir(Vendas) */
    private function menuGraph(): array
    {
        return $this->graph(
            [['i', 'inicio', []], ['m', 'mensagem', ['texto' => 'Oi {{nome}}']], ['mn', 'menu', ['texto' => 'Escolha', 'opcoes' => ['Vendas']]], ['t', 'transferir', ['setor_id' => $this->vendas->id]]],
            [['i', 'out', 'm'], ['m', 'out', 'mn'], ['mn', 'opt:1', 't']],
        );
    }

    public function test_mensagem_menu_e_transferir_registram_log_trilha_e_desfecho(): void
    {
        $state = $this->sim()->start($this->menuGraph(), 'Maria', 'real');

        $this->assertSame('menu', $state->session['waiting']);
        $this->assertSame(['Oi Maria', "Escolha\n\n1 - Vendas\n\nPara falar com um atendente digite #sair"], $this->texts($state, 'bot'));
        $this->assertSame(['i', 'm', 'mn'], $state->trail);
        $this->assertNull($state->outcome);

        $this->sim()->reply($this->menuGraph(), $state, '1');

        $this->assertSame('fila', $state->outcome);
        $this->assertContains('entraria na fila de Vendas', $this->texts($state, 'efeito'));
        $this->assertSame(['i', 'm', 'mn', 't'], $state->trail);
        $this->assertSame(['1'], $this->texts($state, 'cliente'));
    }

    public function test_menu_usa_a_mesma_regra_de_numero_e_sair_do_motor_real(): void
    {
        $state = $this->sim()->start($this->menuGraph(), 'Maria', 'real');
        $this->sim()->reply($this->menuGraph(), $state, 'opção 1.');
        $this->assertSame('fila', $state->outcome);

        $other = $this->sim()->start($this->menuGraph(), 'Maria', 'real');
        $this->sim()->reply($this->menuGraph(), $other, '#sair');
        $this->assertSame('fila', $other->outcome);
        $this->assertContains('entraria na fila de Vendas', $this->texts($other, 'efeito'));
        $this->assertContains('Vou encaminhar você para o setor Vendas. Seu protocolo é TESTE.', $this->texts($other, 'bot'));
    }

    public function test_pergunta_valida_cnpj_e_guarda_formatado(): void
    {
        $g = $this->graph(
            [['i', 'inicio', []], ['p', 'pergunta', ['texto' => 'CNPJ?', 'variavel' => 'cnpj', 'validacao' => 'cnpj']], ['t', 'transferir', []]],
            [['i', 'out', 'p'], ['p', 'out', 't']],
        );

        $state = $this->sim()->start($g, 'Maria', 'real');
        $this->sim()->reply($g, $state, '11222333000181');

        $this->assertSame('11.222.333/0001-81', $state->session['vars']['cnpj']);
    }

    public function test_pergunta_errada_tres_vezes_sai_por_erros(): void
    {
        $g = $this->graph(
            [['i', 'inicio', []], ['p', 'pergunta', ['texto' => 'CNPJ?', 'variavel' => 'cnpj', 'validacao' => 'cnpj']], ['t', 'transferir', []]],
            [['i', 'out', 'p'], ['p', 'out', 't']],
        );

        $state = $this->sim()->start($g, 'Maria', 'real');
        foreach (['a', 'b', 'c'] as $wrong) {
            $this->sim()->reply($g, $state, $wrong);
        }

        $this->assertSame('fila', $state->outcome);
        $this->assertSame(2, count(array_filter($this->texts($state, 'bot'), fn (string $t): bool => $t === 'Resposta inválida. Tente de novo.')));
        $this->assertContains('entraria na fila de Vendas', $this->texts($state, 'efeito'));
    }

    public function test_bloco_apagado_entre_respostas_vai_para_erros(): void
    {
        $state = $this->sim()->start($this->menuGraph(), 'Maria', 'real');
        $without = $this->graph([['i', 'inicio', []], ['t', 'transferir', []]], [['i', 'out', 't']]);

        $this->sim()->reply($without, $state, '1');

        $this->assertSame('fila', $state->outcome);
    }

    public function test_relogio_forcado_escolhe_o_ramo_do_horario(): void
    {
        $g = $this->graph(
            [['i', 'inicio', []], ['c', 'condicao', ['kind' => 'horario']], ['a', 'mensagem', ['texto' => 'Aberto']], ['f', 'mensagem', ['texto' => 'Fechado']], ['t', 'transferir', []]],
            [['i', 'out', 'c'], ['c', 'true', 'a'], ['c', 'false', 'f'], ['a', 'out', 't'], ['f', 'out', 't']],
        );

        $this->assertContains('Aberto', $this->texts($this->sim()->start($g, 'Maria', 'aberto'), 'bot'));
        $this->assertContains('Fechado', $this->texts($this->sim()->start($g, 'Maria', 'fechado'), 'bot'));
    }

    public function test_relogio_fechado_sem_horario_cadastrado_renderiza_proximo_horario(): void
    {
        $g = $this->graph(
            [['i', 'inicio', []], ['m', 'mensagem', ['texto' => 'Voltamos {{proximo_horario}}']], ['t', 'transferir', []]],
            [['i', 'out', 'm'], ['m', 'out', 't']],
        );

        $state = $this->sim()->start($g, 'Maria', 'fechado');

        $this->assertCount(1, array_filter($this->texts($state, 'bot'), fn (string $t): bool => str_starts_with($t, 'Voltamos')));
    }

    public function test_silencio_encaminha_por_timeout_e_so_uma_vez(): void
    {
        $state = $this->sim()->start($this->menuGraph(), 'Maria', 'real');

        $this->sim()->silence($this->menuGraph(), $state);
        $this->assertSame('fila', $state->outcome);

        $before = $state->log;
        $this->sim()->silence($this->menuGraph(), $state);
        $this->assertSame($before, $state->log);
    }

    public function test_resposta_depois_do_desfecho_e_ignorada(): void
    {
        $state = $this->sim()->start($this->menuGraph(), 'Maria', 'real');
        $this->sim()->reply($this->menuGraph(), $state, '1');
        $before = $state->toArray();

        $this->sim()->reply($this->menuGraph(), $state, 'oi de novo');

        $this->assertSame($before, $state->toArray());
    }

    public function test_efeitos_dos_blocos_viram_linhas(): void
    {
        $tag = Tag::factory()->create(['name' => 'VIP']);
        $agent = User::factory()->create(['name' => 'Fulano', 'active' => true]);
        $agent->sectors()->attach($this->vendas);
        Storage::fake('local');
        Storage::disk('local')->put('bot/catalogo.pdf', '%PDF');

        $g = $this->graph(
            [
                ['i', 'inicio', []],
                ['p', 'pergunta', ['texto' => 'Seu nome?', 'variavel' => 'nome_digitado']],
                ['sc', 'salvar_contato', ['campo' => 'name', 'variavel' => 'nome_digitado']],
                ['tg', 'etiquetar', ['tag_id' => $tag->id]],
                ['nt', 'nota', ['texto' => 'Nome: {{nome_digitado}}']],
                ['e', 'esperar', ['segundos' => 3]],
                ['md', 'midia', ['path' => 'bot/catalogo.pdf', 'legenda' => 'Catálogo']],
                ['m', 'mensagem', ['texto' => 'Obrigado, {{nome}}']],
                ['t', 'transferir', ['setor_id' => $this->vendas->id, 'atribuir_a' => $agent->id]],
            ],
            [['i', 'out', 'p'], ['p', 'out', 'sc'], ['sc', 'out', 'tg'], ['tg', 'out', 'nt'], ['nt', 'out', 'e'], ['e', 'out', 'md'], ['md', 'out', 'm'], ['m', 'out', 't']],
        );

        $state = $this->sim()->start($g, 'Maria', 'real');
        $this->sim()->reply($g, $state, 'Ana');

        $this->assertSame([
            'gravaria no contato: name = Ana',
            'etiquetaria "VIP"',
            'registraria a nota: Nome: Ana',
            'esperaria 3 s',
            'enviaria a mídia catalogo.pdf (legenda: Catálogo)',
            'entraria na fila de Vendas, atribuído a Fulano',
        ], $this->texts($state, 'efeito'));
        $this->assertContains('Obrigado, Ana', $this->texts($state, 'bot'), 'o nome salvo no contato vale nas mensagens seguintes');
    }

    public function test_encerrar_marca_o_desfecho(): void
    {
        $g = $this->graph([['i', 'inicio', []], ['e', 'encerrar', []]], [['i', 'out', 'e']]);

        $state = $this->sim()->start($g, 'Maria', 'real');

        $this->assertSame('encerrada', $state->outcome);
        $this->assertContains('encerraria a conversa', $this->texts($state, 'efeito'));
    }

    public function test_passos_acima_do_teto_vao_para_erros(): void
    {
        $g = $this->graph(
            [['i', 'inicio', []], ['a', 'mensagem', ['texto' => 'a']], ['b', 'mensagem', ['texto' => 'b']], ['t', 'transferir', []]],
            [['i', 'out', 'a'], ['a', 'out', 'b'], ['b', 'out', 'a']],
        );

        $state = $this->sim()->start($g, 'Maria', 'real');

        $this->assertSame('fila', $state->outcome);
        $this->assertLessThanOrEqual(60, count($state->log));
    }

    public function test_estado_vira_array_e_volta(): void
    {
        $state = $this->sim()->start($this->menuGraph(), 'Maria', 'real');

        $this->assertEquals($state, SimulationState::fromArray($state->toArray()));
    }
}
