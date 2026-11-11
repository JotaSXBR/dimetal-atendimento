<?php

namespace App\Services\Bot\Nodes;

use App\Services\Bot\BotRun;
use App\Services\Bot\Flow\NodeData;
use App\Services\Bot\Step;

/** Encerra o ticket sem passar por atendente. A sessão é fechada por `CloseTicket`. */
final class EncerrarNode extends Node
{
    public function enter(BotRun $run, NodeData $data): Step
    {
        $run->fx->close();

        return Step::done();
    }
}
