<?php

namespace App\Services\Bot\Nodes;

use App\Models\Tag;
use App\Services\Bot\BotRun;
use App\Services\Bot\Flow\NodeData;
use App\Services\Bot\Step;

final class EtiquetarNode extends Node
{
    public function enter(BotRun $run, NodeData $data): Step
    {
        $tag = Tag::query()->find($data->int('tag_id'));

        if ($tag !== null) {
            $run->ticket->tags()->syncWithoutDetaching([$tag->id]);
        }

        return Step::next();
    }
}
