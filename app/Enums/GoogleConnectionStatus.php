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
}
