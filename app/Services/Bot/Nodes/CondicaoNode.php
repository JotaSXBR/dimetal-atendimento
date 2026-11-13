<?php

namespace App\Services\Bot\Nodes;

use App\Models\Tag;
use App\Services\Bot\BotRun;
use App\Services\Bot\Flow\NodeData;
use App\Services\Bot\Step;

/**
 * Escolhe o caminho `true`/`false` pelo horário de funcionamento (feriados incluídos), por o contato já ter nome, por já ter
 * sido atendido antes (outro ticket dele), por ter uma etiqueta (do contato ou da conversa, conforme o escopo dela) ou pelo valor
 * de uma variável.
 */
final class CondicaoNode extends Node
{
    public function enter(BotRun $run, NodeData $data): Step
    {
        $result = match ($data->string('kind')) {
            'horario' => $run->fx->isOpen(),
            'nome' => $run->fx->contactName() !== '',
            'retorno' => $run->fx->returning(),
            'etiqueta' => $this->hasTag($run, $data),
            default => $this->compare($run, $data),
        };

        return Step::next($result ? 'true' : 'false');
    }

    /** Etiqueta apagada (ou sem escolha) vale como "não tem": o fluxo segue pelo `false`. */
    private function hasTag(BotRun $run, NodeData $data): bool
    {
        $tag = Tag::query()->find($data->int('tag_id'));

        return $tag !== null && $run->fx->hasTag($tag);
    }

    private function compare(BotRun $run, NodeData $data): bool
    {
        $value = $run->get($data->string('var'));
        $actual = is_scalar($value) ? mb_strtolower(trim((string) $value)) : '';
        $expected = mb_strtolower(trim($data->string('valor')));

        return match ($data->string('op', 'igual')) {
            'diferente' => $actual !== $expected,
            'contem' => $expected !== '' && str_contains($actual, $expected),
            'preenchida' => $actual !== '',
            default => $actual === $expected,
        };
    }
}
