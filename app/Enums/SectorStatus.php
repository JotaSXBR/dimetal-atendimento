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

    public function appearsInMenu(): bool
    {
        return $this === self::ATIVO;
    }

    public function receivesTickets(): bool
    {
        return $this !== self::DESATIVADO;
    }
}
