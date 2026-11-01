<?php

namespace App\Services\Bot\Nodes;

use App\Services\Bot\BotRun;
use App\Services\Bot\Step;
use App\Services\Inbox\BusinessHours;

/** Escolhe o caminho `true`/`false` pelo horário de funcionamento (feriados incluídos) ou pelo valor de uma variável. */
final class CondicaoNode extends Node
{
    public function enter(BotRun $run, array $data): Step
    {
        $result = ($data['kind'] ?? '') === 'horario'
            ? app(BusinessHours::class)->isOpen(now())
            : $this->compare($run, $data);

        return Step::next($result ? 'true' : 'false');
    }

    /** @param  array<string, mixed>  $data */
    private function compare(BotRun $run, array $data): bool
    {
        $value = $run->get((string) ($data['var'] ?? ''));
        $actual = is_scalar($value) ? mb_strtolower(trim((string) $value)) : '';
        $expected = mb_strtolower(trim((string) ($data['valor'] ?? '')));

        return match ($data['op'] ?? 'igual') {
            'diferente' => $actual !== $expected,
            'contem' => $expected !== '' && str_contains($actual, $expected),
            'preenchida' => $actual !== '',
            default => $actual === $expected,
        };
    }
}
