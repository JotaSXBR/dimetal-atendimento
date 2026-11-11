<?php

namespace App\Services\Bot\Nodes;

use App\Services\Bot\BotRun;
use App\Services\Bot\Flow\NodeData;
use App\Services\Bot\Step;
use App\Services\Bot\Vars;

/** Envia um arquivo fixo do storage local. Arquivo que sumiu é registrado no log e o fluxo segue. */
final class MidiaNode extends Node
{
    public function enter(BotRun $run, NodeData $data): Step
    {
        $run->fx->media($data->string('path'), Vars::render($data->string('legenda'), $run->vars()));

        return Step::next();
    }
}
