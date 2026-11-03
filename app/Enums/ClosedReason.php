<?php

namespace App\Enums;

enum ClosedReason: string
{
    case MANUAL = 'manual';
    case INATIVIDADE = 'inatividade';
    case MESCLADO = 'mesclado';
    case BOT = 'bot';
    /** Conversa trazida da importação inicial do histórico: nunca foi atendimento deste sistema. */
    case IMPORTADO = 'importado';

    public function label(): string
    {
        return match ($this) {
            self::MANUAL => 'Encerrado por atendente',
            self::INATIVIDADE => 'Encerrado por inatividade',
            self::MESCLADO => 'Mesclado em outro ticket do mesmo cliente',
            self::BOT => 'Encerrado pelo bot',
            self::IMPORTADO => 'Histórico importado',
        };
    }
}
