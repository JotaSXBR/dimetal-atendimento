<?php

namespace App\Services\Bot\Answers;

final class Cep implements Answer
{
    public function parse(string $text): ?string
    {
        $cep = (string) preg_replace('/\D/', '', $text);

        return strlen($cep) === 8 ? substr($cep, 0, 5).'-'.substr($cep, 5) : null;
    }
}
