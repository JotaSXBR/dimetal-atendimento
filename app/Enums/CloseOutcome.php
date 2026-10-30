<?php

namespace App\Enums;

/** Por que o atendente encerrou o ticket (diferente de `ClosedReason`, que diz quem o encerrou). */
enum CloseOutcome: string
{
    case RESOLVIDO = 'resolvido';
    case SEM_RESPOSTA = 'sem_resposta';
    case CLIENTE_PEDIU = 'cliente_pediu';

    public function label(): string
    {
        return match ($this) {
            self::RESOLVIDO => 'Resolvido',
            self::SEM_RESPOSTA => 'Sem resposta',
            self::CLIENTE_PEDIU => 'Cliente pediu',
        };
    }
}
