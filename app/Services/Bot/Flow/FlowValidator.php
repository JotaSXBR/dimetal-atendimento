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
     * @param  list<int>|null  $userIds  pessoas ativas, para o "Atribuir a" (nulo = não conferir)
     * @return list<string>
     */
    public function validate(array $graph, ?array $sectorIds = null, ?array $tagIds = null, ?array $userIds = null): array
    {
        $flow = FlowGraph::fromArray($graph);
        $errors = [];

        if (count(array_filter($flow->ids(), fn (string $id): bool => $flow->type($id) === NodeType::Inicio)) !== 1) {
            $errors[] = 'O workflow precisa de um bloco Início, e só um.';
        }

        foreach ($flow->ids() as $id) {
            array_push($errors, ...$this->checkNode($flow, $id, $sectorIds, $tagIds, $userIds));
        }

        $start = $flow->start();

        return $start === null ? $errors : [...$errors, ...$this->checkReachability($flow, $start)];
    }

    /**
     * @param  list<int>|null  $sectorIds
     * @param  list<int>|null  $tagIds
     * @param  list<int>|null  $userIds
     * @return list<string>
     */
    private function checkNode(FlowGraph $flow, string $id, ?array $sectorIds, ?array $tagIds, ?array $userIds): array
    {
        $type = $flow->type($id);

        if ($type === null) {
            return ["{$id}: tipo desconhecido."];
        }

        $data = $flow->data($id);
        $errors = [...$this->checkText($id, $type, $data), ...$this->checkReferences($id, $type, $data, $sectorIds, $tagIds, $userIds)];

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

        return $errors;
    }

    /** @return list<string> */
    private function checkReachability(FlowGraph $flow, string $start): array
    {
        $errors = [];
        $reachable = $this->reachable($flow, $start);

        foreach ($flow->ids() as $id) {
            if (! isset($reachable[$id])) {
                $errors[] = "{$id}: não é alcançado a partir do Início.";
            }
        }

        if (array_filter(array_keys($reachable), fn (string $id): bool => $flow->type($id)?->isFinal() === true) === []) {
            $errors[] = 'Nenhum caminho termina em Transferir ou Encerrar.';
        }

        if ($this->hasLoopWithoutWaiting($flow, array_keys($reachable))) {
            $errors[] = 'Há um ciclo sem bloco que espere o cliente (Menu, Menu de setores ou Pergunta): o bot ficaria em laço.';
        }

        return $errors;
    }

    /** @return list<string> */
    private function checkText(string $id, NodeType $type, NodeData $data): array
    {
        $needsText = in_array($type, [NodeType::Mensagem, NodeType::MenuSetores, NodeType::Menu, NodeType::Pergunta, NodeType::Nota], true);

        return [
            ...($needsText && ! $data->filled('texto') ? ["{$id}: escreva o texto."] : []),
            ...match ($type) {
                NodeType::Menu => $this->validOptions($data->list('opcoes')) ? [] : ["{$id}: o menu precisa de ao menos uma opção, todas com texto."],
                NodeType::Nota => mb_strlen($data->string('texto')) > NodeType::NOTE_MAX_LENGTH ? ["{$id}: a nota passa de ".NodeType::NOTE_MAX_LENGTH.' caracteres.'] : [],
                NodeType::Midia => $data->filled('path') ? [] : ["{$id}: escolha o arquivo."],
                NodeType::Pergunta => $data->filled('variavel') ? [] : ["{$id}: informe o nome da variável que guarda a resposta."],
                default => [],
            },
        ];
    }

    /**
     * @param  list<int>|null  $sectorIds
     * @param  list<int>|null  $tagIds
     * @param  list<int>|null  $userIds
     * @return list<string>
     */
    private function checkReferences(string $id, NodeType $type, NodeData $data, ?array $sectorIds, ?array $tagIds, ?array $userIds): array
    {
        return match ($type) {
            NodeType::Transferir => [
                ...($data->has('setor_id') && $sectorIds !== null && ! in_array($data->int('setor_id'), $sectorIds, true) ? ["{$id}: o setor {$data->string('setor_id')} não existe."] : []),
                ...($data->has('atribuir_a') && $userIds !== null && ! in_array($data->int('atribuir_a'), $userIds, true) ? ["{$id}: a pessoa {$data->string('atribuir_a')} não existe ou está inativa."] : []),
            ],
            NodeType::Etiquetar => $tagIds !== null && ! in_array($data->int('tag_id'), $tagIds, true) ? ["{$id}: a etiqueta ".($data->has('tag_id') ? $data->string('tag_id') : '?').' não existe.'] : [],
            default => [],
        };
    }

    /** @param  list<mixed>  $options */
    private function validOptions(array $options): bool
    {
        return $options !== [] && array_filter($options, fn (mixed $o): bool => ! is_string($o) || trim($o) === '') === [];
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
