<?php

namespace App\Services\Bot\Nodes;

use App\Models\Tag;
use App\Services\Bot\BotRun;
use App\Services\Bot\Step;

final class EtiquetarNode extends Node
{
    public function enter(BotRun $run, array $data): Step
    {
        $tag = Tag::query()->find((int) ($data['tag_id'] ?? 0));

        if ($tag !== null) {
            $run->ticket->tags()->syncWithoutDetaching([$tag->id]);
        }

        return Step::next();
    }
}
