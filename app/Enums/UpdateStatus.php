<?php

namespace App\Enums;

/** Onde uma atualização do sistema está. Aberta = ainda ocupa a trava com backup e restauração. */
enum UpdateStatus: string
{
    case Conferindo = 'conferindo';
    case Backup = 'backup';
    case Aplicando = 'aplicando';
    case Reiniciando = 'reiniciando';
    case Concluida = 'concluida';
    case Falhou = 'falhou';
    case Revertida = 'revertida';

    /** @return list<self> */
    public static function openCases(): array
    {
        return array_values(array_filter(self::cases(), fn (self $s) => $s->isOpen()));
    }

    public function isOpen(): bool
    {
        return in_array($this, [self::Conferindo, self::Backup, self::Aplicando, self::Reiniciando], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Conferindo => 'Conferindo',
            self::Backup => 'Fazendo backup',
            self::Aplicando => 'Aplicando',
            self::Reiniciando => 'Reiniciando',
            self::Concluida => 'Concluída',
            self::Falhou => 'Falhou',
            self::Revertida => 'Revertida',
        };
    }
}
