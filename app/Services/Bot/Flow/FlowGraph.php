<?php

namespace App\Services\Bot\Flow;

/**
 * O grafo de um workflow: `nodes` (id, type, x, y, data) e `edges` (from, port, to). É dado não confiável: tudo que o
 * motor lê passa por aqui, e o que não casa com o formato é ignorado.
 */
final class FlowGraph
{
    /**
     * @param  array<string, array<string, mixed>>  $nodes  por id
     * @param  array<string, array<string, string>>  $edges  id de origem → porta → id de destino
     */
    private function __construct(private readonly array $nodes, private readonly array $edges) {}

    /** @param  array<string, mixed>  $graph */
    public static function fromArray(array $graph): self
    {
        return new self(self::parseNodes($graph['nodes'] ?? null), self::parseEdges($graph['edges'] ?? null));
    }

    /** @return array<string, array<string, mixed>> */
    private static function parseNodes(mixed $raw): array
    {
        $nodes = [];

        foreach (is_array($raw) ? $raw : [] as $node) {
            if (is_array($node) && is_string($node['id'] ?? null) && is_string($node['type'] ?? null)) {
                /** @var array<string, mixed> $data */
                $data = is_array($node['data'] ?? null) ? $node['data'] : [];
                $nodes[$node['id']] = ['id' => $node['id'], 'type' => $node['type'], 'x' => $node['x'] ?? 0, 'y' => $node['y'] ?? 0, 'data' => $data];
            }
        }

        return $nodes;
    }

    /** @return array<string, array<string, string>> */
    private static function parseEdges(mixed $raw): array
    {
        $edges = [];

        foreach (is_array($raw) ? $raw : [] as $edge) {
            if (is_array($edge) && is_string($edge['from'] ?? null) && is_string($edge['to'] ?? null)) {
                $edges[$edge['from']][is_string($edge['port'] ?? null) ? $edge['port'] : 'out'] = $edge['to'];
            }
        }

        return $edges;
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->nodes);
    }

    /** @return array<string, mixed>|null */
    public function node(string $id): ?array
    {
        return $this->nodes[$id] ?? null;
    }

    public function data(string $id): NodeData
    {
        /** @var array<string, mixed> $data */
        $data = $this->nodes[$id]['data'] ?? [];

        return new NodeData($data);
    }

    public function type(string $id): ?NodeType
    {
        $type = $this->nodes[$id]['type'] ?? null;

        return is_string($type) ? NodeType::tryFrom($type) : null;
    }

    /** O id do único bloco Início (nulo quando não há exatamente um). */
    public function start(): ?string
    {
        $starts = array_keys(array_filter($this->nodes, fn (array $n): bool => $n['type'] === NodeType::Inicio->value));

        return count($starts) === 1 ? $starts[0] : null;
    }

    public function next(string $id, string $port = 'out'): ?string
    {
        return $this->edges[$id][$port] ?? null;
    }

    /** @return list<string> os destinos ligados a partir do bloco */
    public function successors(string $id): array
    {
        return array_values($this->edges[$id] ?? []);
    }
}
