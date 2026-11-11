<?php

namespace App\Services\Bot\Answers;

final class Cpf implements Answer
{
    public function parse(string $text): ?string
    {
        $cpf = (string) preg_replace('/\D/', '', $text);

        if (strlen($cpf) !== 11 || count(array_unique(str_split($cpf))) === 1) {
            return null;
        }

        $base = substr($cpf, 0, 9);
        $first = $this->digit($base);

        if ($cpf !== $base.$first.$this->digit($base.$first)) {
            return null;
        }

        return sprintf('%s.%s.%s-%s', substr($cpf, 0, 3), substr($cpf, 3, 3), substr($cpf, 6, 3), substr($cpf, 9, 2));
    }

    private function digit(string $base): int
    {
        $sum = 0;

        foreach (str_split($base) as $i => $char) {
            $sum += (int) $char * (strlen($base) + 1 - $i);
        }

        $digit = ($sum * 10) % 11;

        return $digit === 10 ? 0 : $digit;
    }
}
