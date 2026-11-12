<?php

namespace App\Services\Backup;

enum BackupKind: string
{
    case Diario = 'diario';
    case Semanal = 'semanal';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Diario => 'Diário',
            self::Semanal => 'Semanal',
            self::Manual => 'Manual',
        };
    }
}
