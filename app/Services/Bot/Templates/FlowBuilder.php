<?php

namespace App\Services\Bot\Templates;

use App\Services\Bot\DefaultWorkflow;
use InvalidArgumentException;

/**
 * Monta o grafo de um modelo sem escrever coordenadas (o editor organiza o desenho na primeira abertura). Id repetido é erro de
 * quem escreve o modelo, por isso estoura na hora.
 */
final class FlowBuilder
{
    /** @var array<string, array{id: string, type: string, data: array<string, mixed>}> */
    private array $nodes = [];

    /** @var list<array{from: string, port: string, to: string}> */
    private array $edges = [];

    public static function start(): self
    {
        return (new self)->node('inicio', 'inicio');
    }

    /** @param  array<string, mixed>  $data */
    public function node(string $id, string $type, array $data = []): self
    {
        if (isset($this->nodes[$id])) {
            throw new InvalidArgumentException("Bloco repetido no modelo: {$id}.");
        }

        $this->nodes[$id] = ['id' => $id, 'type' => $type, 'data' => $data];

        return $this;
    }

    public function link(string $from, string $to, string $port = 'out'): self
    {
        $this->edges[] = ['from' => $from, 'port' => $port, 'to' => $to];

        return $this;
    }

    /**
     * Cria os blocos em sequência e liga o primeiro a `$from` pela porta `$port`; cada um segue ao próximo pela porta `out`.
     *
     * @param  list<array{0: string, 1: string, 2?: array<string, mixed>}>  $steps  `[id, tipo, dados]`
     */
    public function branch(string $from, string $port, array $steps): self
    {
        foreach ($steps as $step) {
            $this->node($step[0], $step[1], $step[2] ?? []);
            $this->link($from, $step[0], $port);
            [$from, $port] = [$step[0], 'out'];
        }

        return $this;
    }

    /**
     * O começo comum dos modelos: dentro do expediente, saudação e menu; fora dele, o aviso do horário e a fila geral, sem perguntas.
     *
     * @param  list<string>  $options  o texto de cada opção; a porta da N-ésima é `opt:N`
     */
    public function opening(string $greeting, string $menuText, array $options): self
    {
        return $this
            ->branch('inicio', 'out', [['horario', 'condicao', ['kind' => 'horario']]])
            ->branch('horario', 'true', [['saudacao', 'mensagem', ['texto' => $greeting]], ['menu', 'menu', ['texto' => $menuText, 'opcoes' => $options, 'timeout_min' => 5]]])
            ->branch('horario', 'false', [
                ['fora', 'mensagem', ['texto' => DefaultWorkflow::OUT_OF_HOURS]],
                ['transferir_fora', 'transferir', ['fila_geral' => true]],
            ]);
    }

    /** @return array{nodes: list<array{id: string, type: string, data: array<string, mixed>}>, edges: list<array{from: string, port: string, to: string}>} */
    public function graph(): array
    {
        return ['nodes' => array_values($this->nodes), 'edges' => $this->edges];
    }
}
