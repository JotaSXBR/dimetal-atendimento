<?php

namespace App\Services\Inbox;

use App\Models\Message;
use App\Models\WahaEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Uma mensagem do WAHA já lida e validada: só existe quando há o que gravar.
 */
final readonly class IncomingMessage
{
    public function __construct(
        public string $waId,
        public bool $fromMe,
        public string $peer,
        public string $type,
        public string $body,
        public CarbonImmutable $sentAt,
        public ?string $alternateJid,
        public ?string $pushName,
        public ?IncomingMedia $media = null,
    ) {}

    /**
     * `null` quando o evento deve ser ignorado (sem id, eco de entrada, grupo, broadcast ou sem conteúdo).
     */
    public static function fromEvent(WahaEvent $event): ?self
    {
        $data = self::relevantPayload($event);
        $peer = $data === null ? null : self::peer($data, $event);
        $content = $peer === null ? null : self::content($data, $event);

        if ($data === null || $peer === null || $content === null) {
            return null;
        }

        $fromMe = (bool) ($data['fromMe'] ?? false);

        return new self(
            waId: Message::waKey($data['id']),
            fromMe: $fromMe,
            peer: $peer,
            type: $content['type'],
            body: $content['body'],
            sentAt: self::sentAt($data['timestamp'] ?? null, $event->received_at),
            alternateJid: self::string(data_get($data, '_data.Info.'.($fromMe ? 'RecipientAlt' : 'SenderAlt'))),
            pushName: $fromMe ? null : self::string(data_get($data, '_data.Info.PushName') ?? $data['notifyName'] ?? null),
            media: $content['media'],
        );
    }

    /**
     * O payload, desde que tenha id e seja o evento certo para a mensagem.
     *
     * @return array{id: string, ...}|null
     */
    private static function relevantPayload(WahaEvent $event): ?array
    {
        $data = $event->payload['payload'] ?? null;

        if (! is_array($data) || ! is_string($data['id'] ?? null) || $data['id'] === '') {
            return null;
        }

        // `message.any` repete as recebidas, que já entram por `message`; só interessa a enviada por outro aparelho.
        return $event->event === 'message.any' && ! ($data['fromMe'] ?? false) ? null : $data;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function peer(array $data, WahaEvent $event): ?string
    {
        $peer = $data[($data['fromMe'] ?? false) ? 'to' : 'from'] ?? null;

        if (is_string($peer) && ! self::isIgnoredChat($peer)) {
            return $peer;
        }

        Log::info('Mensagem do WAHA ignorada (grupo ou broadcast)', ['peer' => $peer, 'event' => $event->waha_event_id]);

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array{type: string, body: string, media: ?IncomingMedia}|null
     */
    private static function content(array $data, WahaEvent $event): ?array
    {
        $hasMedia = (bool) ($data['hasMedia'] ?? false);
        $body = ! $hasMedia && is_string($data['body'] ?? null) ? $data['body'] : '';
        $type = self::typeFor($data, $hasMedia, $body);

        if ($type !== null) {
            return ['type' => $type, 'body' => $body, 'media' => $type === 'media' ? self::media($data) : null];
        }

        Log::info('Mensagem do WAHA sem conteúdo ignorada', ['id' => $data['id'] ?? null, 'event' => $event->waha_event_id]);

        return null;
    }

    /**
     * Só o nome do arquivo no WAHA vira referência; a `url` do GOWS costuma ser um CDN cifrado (`.enc`) ou um endereço
     * interno do container, e nunca é usada. Nome com barra ou `..` é descartado: a rota de mídia o repassaria ao WAHA.
     *
     * @param  array<array-key, mixed>  $data
     */
    private static function media(array $data): IncomingMedia
    {
        $filename = self::string(data_get($data, 'media.filename'));
        $mime = self::string(data_get($data, 'media.mimetype'));
        $size = data_get($data, 'media.size');

        return new IncomingMedia(
            ref: IncomingMedia::safeRef($filename),
            mime: $mime === null ? null : trim(explode(';', $mime)[0]),
            size: is_int($size) && $size >= 0 ? $size : null,
        );
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function isViewOnce(array $data): bool
    {
        $message = data_get($data, '_data.Message');

        return is_array($message) && array_intersect(array_keys($message), ['viewOnceMessage', 'viewOnceMessageV2', 'viewOnceMessageV2Extension']) !== [];
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function isIgnoredChat(string $chat): bool
    {
        return str_ends_with($chat, '@g.us') || $chat === 'status@broadcast' || str_ends_with($chat, '@newsletter');
    }

    /**
     * Tipo da mensagem, ou null quando não há nada para mostrar (sem texto, sem mídia e sem tipo não textual).
     *
     * @param  array<array-key, mixed>  $data
     */
    private static function typeFor(array $data, bool $hasMedia, string $body): ?string
    {
        if ($hasMedia) {
            return 'media';
        }

        if ($body !== '') {
            return 'text';
        }

        if (self::isViewOnce($data)) {
            return 'view_once';
        }

        $type = $data['type'] ?? null;

        return is_string($type) && ! in_array($type, ['', 'chat', 'text'], true) ? $type : null;
    }

    private static function sentAt(mixed $timestamp, CarbonImmutable $fallback): CarbonImmutable
    {
        // Timestamp ausente, zero ou negativo não é data de verdade: usa o horário de recebimento.
        return is_numeric($timestamp) && (int) $timestamp > 0
            ? CarbonImmutable::createFromTimestampUTC((int) $timestamp)
            : $fallback;
    }
}
