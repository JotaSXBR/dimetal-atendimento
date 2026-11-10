<?php

namespace App\Enums;

enum GoogleConnectionStatus: string
{
    case CONNECTED = 'connected';
    case NEEDS_REAUTH = 'needs_reauth';

    public function label(): string
    {
        return match ($this) {
            self::CONNECTED => 'Conectado',
            self::NEEDS_REAUTH => 'Precisa reconectar',
        };
    }

    /** Tom do selo (`x-status-badge`): neutral, bot, ok, warn, danger ou accent. Sem `default`: caso novo precisa de tom. */
    public function tone(): string
    {
        return match ($this) {
            self::CONNECTED => 'ok',
            self::NEEDS_REAUTH => 'warn',
        };
    }
}
