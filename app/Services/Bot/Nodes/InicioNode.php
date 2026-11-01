<?php

namespace App\Services\Bot\Nodes;

use App\Services\Bot\BotRun;
use App\Services\Bot\Flow\NodeData;
use App\Services\Bot\Step;

final class InicioNode extends Node
{
    public function enter(BotRun $run, NodeData $data): Step
    {
        return Step::next();
    }
}
