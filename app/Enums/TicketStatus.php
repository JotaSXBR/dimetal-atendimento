<?php

namespace App\Enums;

enum TicketStatus: string
{
    case AGUARDANDO = 'aguardando';
    case EM_ATENDIMENTO = 'em_atendimento';
    case ENCERRADO = 'encerrado';

    public function label(): string
    {
        return match ($this) {
            self::AGUARDANDO => 'Aguardando',
            self::EM_ATENDIMENTO => 'Em atendimento',
            self::ENCERRADO => 'Encerrado',
        };
    }
}
