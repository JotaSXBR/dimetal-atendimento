<?php

namespace App\Enums;

enum SectorStatus: string
{
    case ATIVO = 'ativo';
    case FORA_DO_MENU = 'fora_do_menu';
    case DESATIVADO = 'desativado';

    public function label(): string
    {
        return match ($this) {
            self::ATIVO => 'Ativo',
            self::FORA_DO_MENU => 'Fora do menu',
            self::DESATIVADO => 'Desativado',
        };
    }

    /** Tom do selo (`x-status-badge`): neutral, bot, ok, warn, danger ou accent. Sem `default`: caso novo precisa de tom. */
    public function tone(): string
    {
        return match ($this) {
            self::ATIVO => 'ok',
            self::FORA_DO_MENU => 'bot',
            self::DESATIVADO => 'neutral',
        };
    }

    public function appearsInMenu(): bool
    {
        return $this === self::ATIVO;
    }

    public function receivesTickets(): bool
    {
        return $this !== self::DESATIVADO;
    }
}
