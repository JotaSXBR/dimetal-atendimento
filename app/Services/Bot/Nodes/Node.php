<?php

namespace App\Services\Bot\Nodes;

use App\Services\Bot\BotRun;
use App\Services\Bot\Step;
use LogicException;

/** Um tipo de bloco. `enter` roda ao chegar; os que esperam o cliente também implementam `answer`. */
abstract class Node
{
    /** @param  array<string, mixed>  $data */
    abstract public function enter(BotRun $run, array $data): Step;

    /** @param  array<string, mixed>  $data */
    public function answer(BotRun $run, array $data, string $text): Step
    {
        throw new LogicException(static::class.' não espera resposta do cliente.');
    }
}
