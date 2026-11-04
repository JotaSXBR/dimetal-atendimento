<?php

namespace App\Services\Inbox;

use App\Services\Waha\WahaRequestException;
use RuntimeException;

/** O WhatsApp não aceitou reagir, editar ou apagar: a mensagem já é o motivo, em português, para a tela. */
class MessageActionFailed extends RuntimeException
{
    public static function fromWaha(WahaRequestException $e): self
    {
        return new self(match (true) {
            $e->statusCode === 404 => 'Mensagem não encontrada no WhatsApp.',
            $e->statusCode >= 400 && $e->statusCode < 500 => 'O WhatsApp recusou a ação (talvez a mensagem esteja fora do prazo).',
            default => self::unreachable()->getMessage(),
        }, 0, $e);
    }

    /** O WAHA não respondeu (queda ou lentidão): nada aconteceu e dá para tentar de novo. */
    public static function unreachable(): self
    {
        return new self('O WhatsApp não respondeu agora. Tente de novo em instantes.');
    }
}
