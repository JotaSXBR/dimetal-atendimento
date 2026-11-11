<?php

namespace App\Support;

use Carbon\CarbonInterface;

/** Há quanto tempo o cliente espera, curto para o card da fila: "agora", "há 12 min", "há 3 h", "há 2 d". */
final class WaitLabel
{
    public static function since(CarbonInterface $since, CarbonInterface $now): string
    {
        $minutes = (int) max(0, $since->diffInMinutes($now, false));

        return match (true) {
            $minutes < 1 => 'agora',
            $minutes < 60 => "há {$minutes} min",
            $minutes < 1440 => 'há '.intdiv($minutes, 60).' h',
            default => 'há '.intdiv($minutes, 1440).' d',
        };
    }
}
