<?php

namespace App\Services\Bot\Answers;

/** Data `dd/mm/aaaa` que existe no calendário (zero à esquerda é opcional). */
final class Data implements Answer
{
    public function parse(string $text): ?string
    {
        if (preg_match('#^\s*(\d{1,2})/(\d{1,2})/(\d{4})\s*$#', $text, $m) !== 1 || ! checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
            return null;
        }

        return sprintf('%02d/%02d/%04d', (int) $m[1], (int) $m[2], (int) $m[3]);
    }
}
