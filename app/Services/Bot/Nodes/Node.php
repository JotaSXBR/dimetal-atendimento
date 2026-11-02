<?php

namespace App\Services\Bot\Nodes;

use App\Services\Bot\BotRun;
use App\Services\Bot\Flow\NodeData;
use App\Services\Bot\Step;
use LogicException;

/** Um tipo de bloco. `enter` roda ao chegar; os que esperam o cliente também implementam `answer`. */
abstract class Node
{
    abstract public function enter(BotRun $run, NodeData $data): Step;

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter")
     */
    public function answer(BotRun $run, NodeData $data, string $text): Step
    {
        throw new LogicException(static::class.' não espera resposta do cliente.');
    }
}
