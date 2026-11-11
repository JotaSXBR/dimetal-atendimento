<?php

namespace App\Services\Inbox;

/**
 * Reconhece um arquivo OGG/Opus mono pela assinatura (a mesma que o gerador de onda do WAHA usa): `OggS` no byte 0, `OpusHead` no
 * byte 28 e um canal no byte 37. É o formato que o WhatsApp aceita como mensagem de voz, então o WAHA não precisa convertê-lo.
 */
final class OggOpus
{
    public static function isMono(string $bytes): bool
    {
        return strlen($bytes) >= 38
            && str_starts_with($bytes, 'OggS')
            && substr($bytes, 28, 8) === 'OpusHead'
            && ord($bytes[37]) === 1;
    }
}
