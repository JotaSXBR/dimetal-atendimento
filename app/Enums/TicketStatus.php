<?php

namespace App\Enums;

enum TicketStatus: string
{
    case PENDENTE = 'pendente';
    case AGUARDANDO = 'aguardando';
    case EM_ATENDIMENTO = 'em_atendimento';
    case ENCERRADO = 'encerrado';

    public function label(): string
    {
        return match ($this) {
            self::PENDENTE => 'Pendente',
            self::AGUARDANDO => 'Aguardando',
            self::EM_ATENDIMENTO => 'Em atendimento',
            self::ENCERRADO => 'Encerrado',
        };
    }

    /** Pendente é a triagem automática (bot ou, depois, LLM); só `aguardando` é a fila de um setor com humanos. */
    public function inHumanQueue(): bool
    {
        return $this === self::AGUARDANDO;
    }
}
