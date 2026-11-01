<?php

namespace App\Services\Bot\Flow;

/**
 * Confere se um grafo pode ser publicado. Devolve as mensagens de erro (vazio = publicável), cada uma começando pelo id
 * do bloco, para o editor destacá-lo.
 */
final class FlowValidator
{
    /**
     * @param  array<string, mixed>  $graph
     * @param  list<int>|null  $sectorIds  setores existentes (nulo = não conferir)
     * @param  list<int>|null  $tagIds  etiquetas existentes (nulo = não conferir)
     * @return list<string>
     */
    public function validate(array $graph, ?array $sectorIds = null, ?array $tagIds = null): array
    {
        $flow = FlowGraph::fromArray($graph);
        $errors = [];

        $starts = array_filter($flow->nodes(), fn (array $n): bool => $n['type'] === NodeType::Inicio->value);
        if (count($starts) !== 1) {
            $errors[] = 'O workflow precisa de um bloco Início, e só um.';
        }

        foreach ($flow->nodes() as $id => $node) {
            $type = $flow->type($id);

            if ($type === null) {
                $errors[] = "{$id}: tipo desconhecido.";

                continue;
            }

            $data = $node['data'];
            array_push($errors, ...$this->checkData($id, $type, $data, $sectorIds, $tagIds));

            foreach ($type->ports($data) as $port) {
                if ($flow->next($id, $port) === null) {
                    $errors[] = "{$id}: saída \"{$port}\" sem ligação.";
                }
            }

            foreach ($flow->successors($id) as $target) {
                if ($flow->node($target) === null) {
                    $errors[] = "{$id}: ligação para um bloco que não existe ({$target}).";
                }
            }
        }

        $start = $flow->start();
        if ($start === null) {
            return $errors;
        }

        $reachable = $this->reachable($flow, $start);

        foreach (array_keys($flow->nodes()) as $id) {
            if (! isset($reachable[$id])) {
                $errors[] = "{$id}: não é alcançado a partir do Início.";
            }
        }

        $hasFinal = array_filter(array_keys($reachable), fn (string $id): bool => $flow->type($id)?->isFinal() === true) !== [];
        if (! $hasFinal) {
            $errors[] = 'Nenhum caminho termina em Transferir ou Encerrar.';
        }

        if ($this->hasLoopWithoutWaiting($flow, array_keys($reachable))) {
            $errors[] = 'Há um ciclo sem bloco que espere o cliente (Menu, Menu de setores ou Pergunta): o bot ficaria em laço.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<int>|null  $sectorIds
     * @param  list<int>|null  $tagIds
     * @return list<string>
     */
    private function checkData(string $id, NodeType $type, array $data, ?array $sectorIds, ?array $tagIds): array
    {
        $errors = [];
        $blank = fn (string $key): bool => ! is_string($data[$key] ?? null) || trim($data[$key]) === '';

        if (in_array($type, [NodeType::Mensagem, NodeType::MenuSetores, NodeType::Menu, NodeType::Pergunta], true) && $blank('texto')) {
            $errors[] = "{$id}: escreva o texto.";
        }

        if ($type === NodeType::Menu) {
            $options = is_array($data['opcoes'] ?? null) ? $data['opcoes'] : [];
            if ($options === [] || array_filter($options, fn (mixed $o): bool => ! is_string($o) || trim($o) === '') !== []) {
                $errors[] = "{$id}: o menu precisa de ao menos uma opção, todas com texto.";
            }
        }

        if ($type === NodeType::Midia && $blank('path')) {
            $errors[] = "{$id}: escolha o arquivo.";
        }

        if ($type === NodeType::Pergunta && $blank('variavel')) {
            $errors[] = "{$id}: informe o nome da variável que guarda a resposta.";
        }

        if ($type === NodeType::Transferir && isset($data['setor_id']) && $sectorIds !== null && ! in_array((int) $data['setor_id'], $sectorIds, true)) {
            $errors[] = "{$id}: o setor {$data['setor_id']} não existe.";
        }

        if ($type === NodeType::Etiquetar && $tagIds !== null && ! in_array((int) ($data['tag_id'] ?? 0), $tagIds, true)) {
            $errors[] = "{$id}: a etiqueta ".($data['tag_id'] ?? '?').' não existe.';
        }

        return $errors;
    }

    /** @return array<string, true> */
    private function reachable(FlowGraph $flow, string $start): array
    {
        $seen = [];
        $stack = [$start];

        while ($stack !== []) {
            $id = array_pop($stack);

            if (isset($seen[$id]) || $flow->node($id) === null) {
                continue;
            }

            $seen[$id] = true;
            array_push($stack, ...$flow->successors($id));
        }

        return $seen;
    }

    /**
     * Um laço sem espera é um ciclo formado só por blocos que não esperam o cliente. Procura ciclo no subgrafo desses blocos.
     *
     * @param  list<string>  $ids
     */
    private function hasLoopWithoutWaiting(FlowGraph $flow, array $ids): bool
    {
        $free = array_filter($ids, fn (string $id): bool => $flow->type($id)?->waits() === false);
        $state = [];

        $visit = function (string $id) use (&$visit, &$state, $flow, $free): bool {
            $state[$id] = 1;

            foreach ($flow->successors($id) as $next) {
                if (! in_array($next, $free, true)) {
                    continue;
                }

                if (($state[$next] ?? 0) === 1 || (($state[$next] ?? 0) === 0 && $visit($next))) {
                    return true;
                }
            }

            $state[$id] = 2;

            return false;
        };

        foreach ($free as $id) {
            if (($state[$id] ?? 0) === 0 && $visit($id)) {
                return true;
            }
        }

        return false;
    }
}
