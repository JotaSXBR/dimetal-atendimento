<?php

namespace App\Services\Bot\Nodes;

use App\Enums\MessageOrigin;
use App\Jobs\SendTicketMessageJob;
use App\Services\Bot\BotRun;
use App\Services\Bot\Flow\NodeData;
use App\Services\Bot\Step;
use App\Services\Bot\Vars;
use App\Services\Inbox\AttachmentRules;
use App\Services\Inbox\SendTicketMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/** Envia um arquivo fixo do storage local. Arquivo que sumiu é registrado no log e o fluxo segue. */
final class MidiaNode extends Node
{
    public function enter(BotRun $run, NodeData $data): Step
    {
        $path = $data->string('path');
        $disk = Storage::disk('local');

        // O caminho vem do grafo (dado não confiável): só vale dentro do storage, sem subir de diretório.
        if ($path === '' || str_contains($path, '..') || ! $disk->exists($path)) {
            Log::warning('Bot: arquivo do bloco Enviar mídia não encontrado', ['ticket' => $run->ticket->id, 'path' => $path]);

            return Step::next();
        }

        $mime = $disk->mimeType($path);

        $message = app(SendTicketMessage::class)->record(
            $run->ticket,
            null,
            'media',
            Vars::render($data->string('legenda'), $run->vars()),
            MessageOrigin::BOT,
            [
                'media_mime' => is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream',
                'media_name' => AttachmentRules::safeName(basename($path)),
                'media_size' => $disk->size($path),
                'local_path' => $path,
            ],
        );
        SendTicketMessageJob::dispatch($message->id)->afterCommit();

        return Step::next();
    }
}
