<?php

namespace App\Services\Bot\Nodes;

use App\Services\Bot\BotRun;
use App\Services\Bot\Step;

/** Menu com opções escritas à mão (`opcoes`); cada uma tem a própria porta `opt:N`. */
final class MenuNode extends ChoiceNode
{
    protected function choices(BotRun $run, array $data): array
    {
        $options = is_array($data['opcoes'] ?? null) ? array_values($data['opcoes']) : [];

        return array_map(fn (mixed $label, int $i): array => ['label' => (string) $label, 'value' => $i + 1], $options, array_keys($options));
    }

    protected function picked(BotRun $run, array $data, int|string $value, int $number): Step
    {
        return Step::next('opt:'.$number);
    }
}
