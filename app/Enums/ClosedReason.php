<?php

namespace App\Enums;

enum ClosedReason: string
{
    case MANUAL = 'manual';
    case INATIVIDADE = 'inatividade';

    public function label(): string
    {
        return match ($this) {
            self::MANUAL => 'Encerrado por atendente',
            self::INATIVIDADE => 'Encerrado por inatividade',
        };
    }
}
