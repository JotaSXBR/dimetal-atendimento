<?php

namespace App\Services\Backup;

enum BackupKind: string
{
    case Diario = 'diario';
    case Semanal = 'semanal';
    case Manual = 'manual';
    /** Backup de segurança que a restauração faz do estado atual antes de trocar o banco. */
    case Antes = 'antes';

    public function label(): string
    {
        return match ($this) {
            self::Diario => 'Diário',
            self::Semanal => 'Semanal',
            self::Manual => 'Manual',
            self::Antes => 'Antes de restaurar',
        };
    }
}
