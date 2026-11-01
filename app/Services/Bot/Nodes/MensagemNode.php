<?php

namespace App\Services\Bot\Nodes;

use App\Services\Bot\BotRun;
use App\Services\Bot\Step;

final class MensagemNode extends Node
{
    public function enter(BotRun $run, array $data): Step
    {
        $run->say((string) ($data['texto'] ?? ''));

        return Step::next();
    }
}
