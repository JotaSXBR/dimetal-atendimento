<?php

namespace App\Services\Bot\Nodes;

use App\Services\Bot\BotRun;
use App\Services\Bot\Flow\NodeData;
use App\Services\Bot\Step;

/** Menu com opções escritas à mão (`opcoes`); cada uma tem a própria porta `opt:N`. */
final class MenuNode extends ChoiceNode
{
    protected function choices(BotRun $run, NodeData $data): array
    {
        $choices = [];

        foreach ($data->list('opcoes') as $i => $label) {
            $choices[] = ['label' => is_scalar($label) ? (string) $label : '', 'value' => $i + 1];
        }

        return $choices;
    }

    protected function picked(BotRun $run, NodeData $data, int|string $value, int $number): Step
    {
        return Step::next('opt:'.$number);
    }
}
