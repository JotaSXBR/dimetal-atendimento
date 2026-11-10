<?php

namespace App\Enums;

enum ChannelStatus: string
{
    case STOPPED = 'STOPPED';
    case STARTING = 'STARTING';
    case SCAN_QR_CODE = 'SCAN_QR_CODE';
    case PASSKEY_REQUIRED = 'PASSKEY_REQUIRED';
    case PASSKEY_CONFIRMATION_REQUIRED = 'PASSKEY_CONFIRMATION_REQUIRED';
    case WORKING = 'WORKING';
    case FAILED = 'FAILED';

    public function label(): string
    {
        return match ($this) {
            self::STOPPED => 'Parado',
            self::STARTING => 'Iniciando',
            self::SCAN_QR_CODE => 'Aguardando QR',
            self::PASSKEY_REQUIRED => 'Senha do aparelho',
            self::PASSKEY_CONFIRMATION_REQUIRED => 'Confirmar senha do aparelho',
            self::WORKING => 'Conectado',
            self::FAILED => 'Falhou',
        };
    }

    /** Tom do selo (`x-status-badge`): neutral, bot, ok, warn, danger ou accent. Sem `default`: caso novo precisa de tom. */
    public function tone(): string
    {
        return match ($this) {
            self::WORKING => 'ok',
            self::FAILED => 'danger',
            self::SCAN_QR_CODE, self::PASSKEY_REQUIRED, self::PASSKEY_CONFIRMATION_REQUIRED => 'warn',
            self::STOPPED, self::STARTING => 'neutral',
        };
    }

    public function needsQr(): bool
    {
        return $this === self::SCAN_QR_CODE;
    }

    public function isProblem(): bool
    {
        return $this === self::FAILED;
    }
}
