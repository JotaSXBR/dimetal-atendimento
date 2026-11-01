<?php

namespace Tests\Unit\Bot;

use App\Services\Bot\Flow\FlowGraph;
use App\Services\Bot\Flow\FlowValidator;
use App\Services\Bot\Flow\NodeType;
use PHPUnit\Framework\TestCase;

class FlowValidatorTest extends TestCase
{
    /**
     * @param  list<array{string, string, array<string, mixed>}>  $nodes  [id, tipo, dados]
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

    /** @return array<string, mixed> */
    private function minimal(): array
    {
        return $this->graph(
            [['i', 'inicio', []], ['m', 'mensagem', ['texto' => 'Oi']], ['t', 'transferir', []]],
            [['i', 'out', 'm'], ['m', 'out', 't']],
        );
    }

    /** @param  list<string>  $errors */
    private function assertHasError(array $errors, string $needle): void
    {
        $this->assertTrue(
            collect($errors)->contains(fn (string $e): bool => str_contains($e, $needle)),
            "Esperava um erro com '{$needle}', veio: ".json_encode($errors, JSON_UNESCAPED_UNICODE),
        );
    }

    public function test_grafo_minimo_e_valido(): void
    {
        $this->assertSame([], (new FlowValidator)->validate($this->minimal()));
    }

    public function test_exige_exatamente_um_inicio(): void
    {
        $none = $this->graph([['m', 'mensagem', ['texto' => 'Oi']], ['t', 'transferir', []]], [['m', 'out', 't']]);
        $this->assertHasError((new FlowValidator)->validate($none), 'um bloco Início');

        $two = $this->minimal();
        $two['nodes'][] = ['id' => 'i2', 'type' => 'inicio', 'x' => 0, 'y' => 0, 'data' => []];
        $two['edges'][] = ['from' => 'i2', 'port' => 'out', 'to' => 'm'];
        $this->assertHasError((new FlowValidator)->validate($two), 'um bloco Início');
    }

    public function test_recusa_tipo_desconhecido(): void
    {
        $graph = $this->minimal();
        $graph['nodes'][] = ['id' => 'x', 'type' => 'magia', 'x' => 0, 'y' => 0, 'data' => []];

        $this->assertHasError((new FlowValidator)->validate($graph), 'tipo desconhecido');
    }

    public function test_bloco_inalcancavel(): void
    {
        $graph = $this->minimal();
        $graph['nodes'][] = ['id' => 'solto', 'type' => 'mensagem', 'x' => 0, 'y' => 0, 'data' => ['texto' => 'Ninguém chega aqui']];
        $graph['edges'][] = ['from' => 'solto', 'port' => 'out', 'to' => 't'];

        $this->assertHasError((new FlowValidator)->validate($graph), 'solto: não é alcançado');
    }

    public function test_saida_sem_ligacao(): void
    {
        $graph = $this->graph([['i', 'inicio', []], ['m', 'mensagem', ['texto' => 'Oi']], ['t', 'transferir', []]], [['i', 'out', 'm']]);
        $graph['edges'][] = ['from' => 'i', 'port' => 'out', 'to' => 't'];

        $this->assertHasError((new FlowValidator)->validate($graph), 'm: saída "out" sem ligação');
    }

    public function test_exige_um_bloco_final(): void
    {
        $graph = $this->graph(
            [['i', 'inicio', []], ['p', 'pergunta', ['texto' => 'Nome?', 'variavel' => 'nome', 'validacao' => 'texto']]],
            [['i', 'out', 'p'], ['p', 'out', 'p']],
        );

        $this->assertHasError((new FlowValidator)->validate($graph), 'Transferir ou Encerrar');
    }

    public function test_ciclo_sem_bloco_que_espera_o_cliente_e_recusado(): void
    {
        $graph = $this->graph(
            [['i', 'inicio', []], ['a', 'mensagem', ['texto' => 'A']], ['b', 'mensagem', ['texto' => 'B']], ['t', 'transferir', []], ['c', 'condicao', ['kind' => 'horario']]],
            [['i', 'out', 'a'], ['a', 'out', 'b'], ['b', 'out', 'c'], ['c', 'true', 'a'], ['c', 'false', 't']],
        );

        $this->assertHasError((new FlowValidator)->validate($graph), 'ciclo');
    }

    public function test_ciclo_com_menu_e_valido(): void
    {
        $graph = $this->graph(
            [['i', 'inicio', []], ['m', 'menu', ['texto' => 'Escolha', 'opcoes' => ['Ver de novo', 'Falar com alguém']]], ['t', 'transferir', []]],
            [['i', 'out', 'm'], ['m', 'opt:1', 'm'], ['m', 'opt:2', 't']],
        );

        $this->assertSame([], (new FlowValidator)->validate($graph));
    }

    public function test_menu_exige_uma_ligacao_por_opcao(): void
    {
        $graph = $this->graph(
            [['i', 'inicio', []], ['m', 'menu', ['texto' => 'Escolha', 'opcoes' => ['A', 'B']]], ['t', 'transferir', []]],
            [['i', 'out', 'm'], ['m', 'opt:1', 't']],
        );

        $this->assertHasError((new FlowValidator)->validate($graph), 'm: saída "opt:2" sem ligação');
    }

    public function test_setor_e_etiqueta_precisam_existir(): void
    {
        $graph = $this->graph(
            [['i', 'inicio', []], ['e', 'etiquetar', ['tag_id' => 99]], ['t', 'transferir', ['setor_id' => 77]]],
            [['i', 'out', 'e'], ['e', 'out', 't']],
        );

        $errors = (new FlowValidator)->validate($graph, sectorIds: [1, 2], tagIds: [5]);

        $this->assertHasError($errors, 't: o setor 77 não existe');
        $this->assertHasError($errors, 'e: a etiqueta 99 não existe');
        $this->assertSame([], (new FlowValidator)->validate($graph), 'sem listas, a existência não é conferida');
    }

    public function test_campos_obrigatorios_dos_blocos(): void
    {
        $graph = $this->graph(
            [['i', 'inicio', []], ['m', 'mensagem', ['texto' => '  ']], ['t', 'transferir', []]],
            [['i', 'out', 'm'], ['m', 'out', 't']],
        );

        $this->assertHasError((new FlowValidator)->validate($graph), 'm: escreva o texto');
    }

    public function test_flow_graph_navega_pelas_ligacoes(): void
    {
        $flow = FlowGraph::fromArray($this->minimal());

        $this->assertSame('i', $flow->start());
        $this->assertSame('m', $flow->next('i'));
        $this->assertSame('t', $flow->next('m', 'out'));
        $this->assertNull($flow->next('t'));
        $this->assertSame('mensagem', $flow->node('m')['type'] ?? null);
        $this->assertNull($flow->node('nao-existe'));
        $this->assertTrue(NodeType::Menu->waits());
        $this->assertTrue(NodeType::Encerrar->isFinal());
        $this->assertFalse(NodeType::Mensagem->waits());
    }
}
