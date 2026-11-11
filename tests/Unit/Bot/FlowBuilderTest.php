<?php

namespace Tests\Unit\Bot;

use App\Services\Bot\Templates\FlowBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class FlowBuilderTest extends TestCase
{
    /** @return list<string> */
    private function ids(array $graph): array
    {
        return array_map(fn (array $n): string => $n['id'], $graph['nodes']);
    }

    public function test_branch_liga_a_porta_e_encadeia_em_out(): void
    {
        $graph = FlowBuilder::start()
            ->node('menu', 'menu', ['texto' => 'm', 'opcoes' => ['a']])
            ->branch('menu', 'opt:1', [['a', 'mensagem', ['texto' => 'x']], ['b', 'nota', ['texto' => 'y']]])
            ->graph();

        $this->assertContains(['from' => 'menu', 'port' => 'opt:1', 'to' => 'a'], $graph['edges']);
        $this->assertContains(['from' => 'a', 'port' => 'out', 'to' => 'b'], $graph['edges']);
        $this->assertSame('y', $graph['nodes'][array_search('b', $this->ids($graph), true)]['data']['texto']);
    }

    public function test_opening_monta_horario_menu_e_ramo_fechado(): void
    {
        $graph = FlowBuilder::start()->opening('Olá!', 'Escolha:', ['Um', 'Dois'])->graph();

        $this->assertEqualsCanonicalizing(['inicio', 'horario', 'saudacao', 'menu', 'fora', 'transferir_fora'], $this->ids($graph));

        $byId = array_column($graph['nodes'], null, 'id');
        $this->assertSame(['Um', 'Dois'], $byId['menu']['data']['opcoes']);
        $this->assertSame('horario', $byId['horario']['data']['kind']);
        $this->assertTrue($byId['transferir_fora']['data']['fila_geral']);
        $this->assertContains(['from' => 'inicio', 'port' => 'out', 'to' => 'horario'], $graph['edges']);
        $this->assertContains(['from' => 'horario', 'port' => 'true', 'to' => 'saudacao'], $graph['edges']);
        $this->assertContains(['from' => 'horario', 'port' => 'false', 'to' => 'fora'], $graph['edges']);
        $this->assertContains(['from' => 'saudacao', 'port' => 'out', 'to' => 'menu'], $graph['edges']);
        $this->assertContains(['from' => 'fora', 'port' => 'out', 'to' => 'transferir_fora'], $graph['edges']);
    }

    public function test_graph_nao_traz_posicao(): void
    {
        foreach (FlowBuilder::start()->opening('a', 'b', ['c'])->graph()['nodes'] as $node) {
            $this->assertArrayNotHasKey('x', $node);
            $this->assertArrayNotHasKey('y', $node);
        }
    }

    public function test_id_repetido_e_erro_do_autor_do_modelo(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FlowBuilder::start()->node('inicio', 'mensagem');
    }
}
