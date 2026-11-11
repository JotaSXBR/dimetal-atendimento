<?php

namespace App\Services\Bot\Nodes;

use App\Enums\NoteOrigin;
use App\Models\TicketNote;
use App\Services\Bot\BotRun;
use App\Services\Bot\Flow\NodeData;
use App\Services\Bot\Step;
use App\Services\Bot\Vars;

/** Registra uma nota interna (só a equipe vê) com o que o bot coletou; variável que não existe vira vazio. */
final class NotaNode extends Node
{
    public const MAX_LENGTH = 1000;

    public function enter(BotRun $run, NodeData $data): Step
    {
        // O `Vars` deixa `{{x}}` literal quando não conhece `x` (serve ao cliente); na nota da equipe o desconhecido some.
        $body = trim((string) preg_replace('/\{\{\s*\w+\s*\}\}/u', '', Vars::render($data->string('texto'), $run->vars())));

        if ($body !== '') {
            TicketNote::create(['ticket_id' => $run->ticket->id, 'origin' => NoteOrigin::BOT, 'body' => $body]);
        }

        return Step::next();
    }
}
