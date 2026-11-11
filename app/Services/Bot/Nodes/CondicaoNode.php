<?php

namespace App\Services\Bot\Nodes;

use App\Models\Ticket;
use App\Services\Bot\BotRun;
use App\Services\Bot\Flow\NodeData;
use App\Services\Bot\Step;
use App\Services\Inbox\BusinessHours;

/**
 * Escolhe o caminho `true`/`false` pelo horário de funcionamento (feriados incluídos), por o contato já ter nome, por já ter
 * sido atendido antes (outro ticket dele) ou pelo valor de uma variável.
 */
final class CondicaoNode extends Node
{
    public function enter(BotRun $run, NodeData $data): Step
    {
        $result = match ($data->string('kind')) {
            'horario' => app(BusinessHours::class)->isOpen(now()),
            'nome' => trim((string) $run->ticket->contact()->first()?->name) !== '',
            'retorno' => Ticket::query()->where('contact_id', $run->ticket->contact_id)->whereKeyNot($run->ticket->id)->exists(),
            default => $this->compare($run, $data),
        };

        return Step::next($result ? 'true' : 'false');
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
