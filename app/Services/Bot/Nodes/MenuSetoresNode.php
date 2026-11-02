<?php

namespace App\Services\Bot\Nodes;

use App\Models\Sector;
use App\Services\Bot\BotRun;
use App\Services\Bot\Flow\NodeData;
use App\Services\Bot\Step;

/** Menu com os setores ativos no menu, na ordem do menu. A escolha fica em `setor_id` e `setor`. */
final class MenuSetoresNode extends ChoiceNode
{
    protected function choices(BotRun $run, NodeData $data): array
    {
        return array_values(Sector::query()->visibleInMenu()->orderBy('menu_order')->orderBy('id')->get()
            ->map(fn (Sector $s): array => ['label' => $s->name, 'value' => $s->id])
            ->all());
    }

    protected function picked(BotRun $run, NodeData $data, int|string $value, int $number): Step
    {
        $sector = Sector::query()->find($value);

        $run->set('setor_id', $sector?->id);
        $run->set('setor', $sector !== null ? $sector->name : '');

        return Step::next('setor');
    }
}
