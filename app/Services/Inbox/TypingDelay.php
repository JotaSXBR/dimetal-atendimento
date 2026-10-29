<?php

namespace App\Services\Inbox;

/**
 * Tempo de "digitando" antes de enviar: de 1 a 6 s, crescendo com o texto e com variação aleatória. Tempo fixo é tão
 * suspeito para o WhatsApp quanto nenhum.
 */
final class TypingDelay
{
    private const MIN_MS = 1000;

    private const MAX_MS = 6000;

    private const PER_CHARACTER_MS = 35;

    private const JITTER_MS = 800;

    public static function milliseconds(string $text): int
    {
        $base = self::MIN_MS + mb_strlen($text) * self::PER_CHARACTER_MS;

        return min(self::MAX_MS, max(self::MIN_MS, $base + random_int(-self::JITTER_MS, self::JITTER_MS)));
    }
}
