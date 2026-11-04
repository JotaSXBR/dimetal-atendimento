<?php

namespace App\Services\Inbox;

use App\Models\WahaEvent;

/** De onde a mensagem veio: do webhook (ao vivo), de uma recuperação após queda ou da importação inicial. */
enum IngestMode: string
{
    case LIVE = 'live';
    case RECOVERED = 'recovered';
    case IMPORTED = 'imported';

    /** O modo marcado no envelope pelo serviço de recuperação ou importação; evento de webhook não leva marca e é `LIVE`. */
    public static function of(WahaEvent $event): self
    {
        $mode = $event->payload['mode'] ?? null;

        return (is_string($mode) ? self::tryFrom($mode) : null) ?? self::LIVE;
    }
}
