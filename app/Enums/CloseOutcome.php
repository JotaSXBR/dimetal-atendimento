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

    /** Tom do selo (`x-status-badge`): neutral, bot, ok, warn, danger ou accent. Sem `default`: caso novo precisa de tom. */
    public function tone(): string
    {
        return match ($this) {
            self::RESOLVIDO => 'ok',
            self::SEM_RESPOSTA, self::CLIENTE_PEDIU => 'neutral',
        };
    }
}
