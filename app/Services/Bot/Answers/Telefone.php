<?php

namespace App\Services\Bot\Answers;

/** Telefone brasileiro com DDD (10 ou 11 dígitos); aceita `+55` na frente. O celular de 11 dígitos começa com 9 depois do DDD. */
final class Telefone implements Answer
{
    public function parse(string $text): ?string
    {
        $digits = (string) preg_replace('/\D/', '', $text);

        if (in_array(strlen($digits), [12, 13], true) && str_starts_with($digits, '55')) {
            $digits = substr($digits, 2);
        }

        $valid = preg_match('/^[1-9][1-9]\d{8}$/', $digits) === 1 || preg_match('/^[1-9][1-9]9\d{8}$/', $digits) === 1;

        if (! $valid) {
            return null;
        }

        $local = substr($digits, 2);

        return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($local, 0, strlen($local) - 4), substr($local, -4));
    }
}
