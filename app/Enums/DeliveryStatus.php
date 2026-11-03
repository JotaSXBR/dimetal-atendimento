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

    /** O código `ack` do WAHA: -1 falhou, 1 enviado, 2 entregue, 3 lido, 4 reproduzido; 0 e demais não dizem nada. */
    public static function fromAck(mixed $code): ?self
    {
        return match (is_numeric($code) ? (int) $code : null) {
            -1 => self::FAILED,
            1 => self::SENT,
            2 => self::DELIVERED,
            3, 4 => self::READ,
            default => null,
        };
    }

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
