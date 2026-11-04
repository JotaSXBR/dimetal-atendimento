<?php

namespace App\Services\Inbox;

use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Models\Message;
use Illuminate\Support\Str;

/** A mensagem citada numa resposta: quem escreveu, o tipo e um trecho de até 200 caracteres. */
final class Quote
{
    private const EXCERPT_LIMIT = 200;

    /** @return array{author: string, type: string, excerpt: string} */
    public static function snapshot(Message $target): array
    {
        return [
            'author' => self::author($target),
            'type' => $target->type,
            'excerpt' => $target->body !== '' ? Str::limit($target->body, self::EXCERPT_LIMIT, '') : self::kind($target),
        ];
    }

    private static function author(Message $message): string
    {
        if ($message->direction === MessageDirection::IN) {
            return $message->contact()->first()?->label() ?? 'Cliente';
        }

        return $message->origin === MessageOrigin::PAINEL ? ($message->author()->first()?->name ?? 'Atendente') : 'Empresa';
    }

    /** O nome do tipo quando não há texto, como a prévia da lista. */
    private static function kind(Message $message): string
    {
        $mime = (string) $message->media_mime;

        return match (true) {
            $mime === 'image/webp' => 'Figurinha',
            str_starts_with($mime, 'image/') => 'Foto',
            str_starts_with($mime, 'audio/') => 'Áudio',
            str_starts_with($mime, 'video/') => 'Vídeo',
            default => $message->hasMedia() ? 'Arquivo' : '['.$message->type.']',
        };
    }
}
