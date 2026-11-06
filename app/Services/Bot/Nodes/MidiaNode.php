<?php

namespace App\Services\Bot\Nodes;

use App\Enums\MessageOrigin;
use App\Jobs\SendTicketMessageJob;
use App\Models\Message;
use App\Services\Bot\BotRun;
use App\Services\Bot\Flow\NodeData;
use App\Services\Bot\Step;
use App\Services\Bot\Vars;
use App\Services\Inbox\AttachmentRules;
use App\Services\Inbox\SendTicketMessage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
        $copy = $this->copyToMediaDisk($disk, $path);

        if ($copy === null) {
            Log::warning('Bot: não foi possível copiar o arquivo do bloco Enviar mídia', ['ticket' => $run->ticket->id, 'path' => $path]);

            return Step::next();
        }

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
                'media_path' => $copy,
            ],
        );
        SendTicketMessageJob::dispatch($message->id)->afterCommit();

        return Step::next();
    }

    /**
     * O arquivo do bloco é a fonte (disco `local`, pasta `bot/`); o anexo é uma cópia no disco de mídia, que a purga de 90 dias
     * pode apagar sem tocar na fonte.
     */
    private function copyToMediaDisk(Filesystem $source, string $path): ?string
    {
        $stream = $source->readStream($path);
        $key = 'outbound/'.now()->format('Y/m').'/'.Str::uuid()->toString().'.'.pathinfo($path, PATHINFO_EXTENSION);

        if ($stream === null || Message::mediaDisk()->writeStream($key, $stream) === false) {
            return null;
        }

        return $key;
    }
}
