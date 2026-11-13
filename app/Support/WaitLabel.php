<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Há quanto tempo, curto para o card da fila, no mesmo texto que o navegador escreve depois (`resources/js/relative-time.js`, estilo
 * `narrow`): "há 5 seg.", "há 12 min.", "há 3 h", "há 2 dias". Igual de propósito: sem isso o card troca de texto ao carregar.
 */
final class WaitLabel
{
    public static function narrow(CarbonInterface $since, CarbonInterface $now): string
    {
        $seconds = (int) max(0, round($since->diffInSeconds($now, false)));
        $days = intdiv($seconds, 86400);

        return match (true) {
            $days >= 1 => "há {$days} ".($days === 1 ? 'dia' : 'dias'),
            $seconds >= 3600 => 'há '.intdiv($seconds, 3600).' h',
            $seconds >= 60 => 'há '.intdiv($seconds, 60).' min.',
            default => "há {$seconds} seg.",
        };
    }
}
