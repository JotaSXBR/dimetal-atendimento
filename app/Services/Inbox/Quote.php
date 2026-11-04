<?php

namespace App\Services\Inbox;

use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Models\Channel;
use App\Models\Message;
use App\Support\MediaPresentation;
use Illuminate\Support\Str;

/** A mensagem citada numa resposta: quem escreveu, o tipo e um trecho de até 200 caracteres. */
final class Quote
{
    private const EXCERPT_LIMIT = 200;

    /**
     * `media` só existe quando a citada é mídia: o id local dela (a miniatura vem da rota `midia`), o tipo e o nome do arquivo.
     *
     * @return array{author: string, type: string, excerpt: string, media?: array{message_id: int, kind: string, name: string|null}}
     */
    public static function snapshot(Message $target): array
    {
        $snapshot = [
            'author' => self::author($target),
            'type' => $target->type,
            'excerpt' => $target->body !== '' ? Str::limit($target->body, self::EXCERPT_LIMIT, '') : self::kind($target),
        ];

        return $target->hasMedia()
            ? [...$snapshot, 'media' => ['message_id' => $target->id, 'kind' => MediaPresentation::kind($target->media_mime), 'name' => $target->media_name]]
            : $snapshot;
    }

    /**
     * A citação de uma mensagem que chegou. O WAHA entrega o `replyTo` normalizado (`id` e `body`) e, junto, o evento cru do
     * GOWS (`_data.Message.<tipo>.contextInfo.stanzaID` e `quotedMessage`); lê um e depois o outro. Se a citada já está aqui, o
     * trecho vem dela; senão, do texto que veio no evento. Formato desconhecido: nenhuma citação, a mensagem entra do mesmo jeito.
     *
     * @param  array<mixed>  $data
     * @return array{wa_id: string, snapshot: array{author: string, type: string, excerpt: string}}|null
     */
    public static function fromPayload(array $data, ?Channel $channel): ?array
    {
        $ref = self::fromReplyTo($data) ?? self::fromContext($data);

        if ($ref === null) {
            return null;
        }

        $waId = Message::waKey($ref['id']);
        $local = $channel === null ? null : Message::query()->where('channel_id', $channel->id)->where('wa_message_id', $waId)->first();

        return [
            'wa_id' => $waId,
            'snapshot' => $local !== null
                ? self::snapshot($local)
                : ['author' => 'Mensagem anterior', 'type' => 'text', 'excerpt' => $ref['text'] !== '' ? Str::limit($ref['text'], self::EXCERPT_LIMIT, '') : 'Mensagem'],
        ];
    }

    /**
     * @param  array<mixed>  $data
     * @return array{id: string, text: string}|null
     */
    private static function fromReplyTo(array $data): ?array
    {
        $reply = $data['replyTo'] ?? null;
        $id = is_array($reply) ? ($reply['id'] ?? null) : null;

        return is_string($id) && $id !== '' ? ['id' => $id, 'text' => is_string($reply['body'] ?? null) ? $reply['body'] : ''] : null;
    }

    /**
     * @param  array<mixed>  $data
     * @return array{id: string, text: string}|null
     */
    private static function fromContext(array $data): ?array
    {
        $message = data_get($data, '_data.Message');

        foreach (is_array($message) ? $message : [] as $part) {
            $context = is_array($part) ? ($part['contextInfo'] ?? null) : null;
            $id = is_array($context) ? ($context['stanzaID'] ?? null) : null;

            if (is_string($id) && $id !== '') {
                return ['id' => $id, 'text' => self::quotedText($context['quotedMessage'] ?? null)];
            }
        }

        return null;
    }

    /** O texto da mensagem citada, em qualquer tipo: conversa, texto estendido ou legenda de mídia. */
    private static function quotedText(mixed $quoted): string
    {
        if (! is_array($quoted)) {
            return '';
        }

        $texts = [$quoted['conversation'] ?? null, data_get($quoted, 'extendedTextMessage.text')];

        foreach ($quoted as $part) {
            $texts[] = is_array($part) ? ($part['caption'] ?? null) : null;
        }

        $found = collect($texts)->first(fn ($text): bool => is_string($text) && $text !== '');

        return is_string($found) ? $found : '';
    }

    private static function author(Message $message): string
    {
        if ($message->direction === MessageDirection::IN) {
            return $message->contact()->first()?->label() ?? 'Cliente';
        }

        return $message->origin === MessageOrigin::PAINEL ? ($message->author()->first()->name ?? 'Atendente') : 'Empresa';
    }

    /** O nome do tipo quando não há texto, como a prévia da lista. */
    private static function kind(Message $message): string
    {
        return match (true) {
            ! $message->hasMedia() => '['.$message->type.']',
            default => match (MediaPresentation::kind($message->media_mime)) {
                'sticker' => 'Figurinha',
                'image' => 'Foto',
                'audio' => 'Áudio',
                'video' => 'Vídeo',
                default => $message->media_name ?? 'Arquivo',
            },
        };
    }
}
