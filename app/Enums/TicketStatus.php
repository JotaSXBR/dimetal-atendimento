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

    /** Tom do selo (`x-status-badge`): neutral, bot, ok, warn, danger ou accent. Sem `default`: caso novo precisa de tom. */
    public function tone(): string
    {
        return match ($this) {
            self::PENDENTE => 'bot',
            self::AGUARDANDO => 'warn',
            self::EM_ATENDIMENTO => 'ok',
            self::ENCERRADO => 'neutral',
        };
    }

    /** Pendente é a triagem automática (bot ou, depois, LLM); só `aguardando` é a fila de um setor com humanos. */
    public function inHumanQueue(): bool
    {
        return $this === self::AGUARDANDO;
    }
}
