<?php

namespace App\Enums;

enum DeliveryStatus: string
{
    /** Aguardando a conexão do WhatsApp: nada saiu ainda, e a caixa de saída envia quando a sessão voltar. */
    case QUEUED = 'queued';
    case SENT = 'sent';
    case DELIVERED = 'delivered';
    case READ = 'read';
    case FAILED = 'failed';

    /** Posição na progressão de entrega; falha fica fora dela. */
    public function rank(): int
    {
        return match ($this) {
            self::SENT => 1,
            self::DELIVERED => 2,
            self::READ => 3,
            self::QUEUED, self::FAILED => 0,
        };
    }
}
