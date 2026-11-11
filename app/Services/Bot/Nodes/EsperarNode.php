<?php

namespace App\Services\Bot\Nodes;

use App\Services\Bot\BotRun;
use App\Services\Bot\Flow\NodeData;
use App\Services\Bot\Step;

/** Pausa de 1 a 30 s mostrando "digitando…" ao cliente. */
final class EsperarNode extends Node
{
    public const MIN_SECONDS = 1;

    public const MAX_SECONDS = 30;

    public function enter(BotRun $run, NodeData $data): Step
    {
        $run->fx->wait(max(self::MIN_SECONDS, min(self::MAX_SECONDS, $data->int('segundos', self::MIN_SECONDS))));

        return Step::next();
    }
}
