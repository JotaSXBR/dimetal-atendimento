<?php

namespace App\Services\Bot\Answers;

/** CNPJ numérico ou alfanumérico (12 posições de 0-9 e A-Z, mais 2 dígitos): módulo 11 com valor do caractere igual ao ASCII menos 48. */
final class Cnpj implements Answer
{
    public function parse(string $text): ?string
    {
        $cnpj = strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $text));

        if (preg_match('/^[0-9A-Z]{12}\d{2}$/', $cnpj) !== 1 || count(array_unique(str_split($cnpj))) === 1) {
            return null;
        }

        $base = substr($cnpj, 0, 12);
        $first = $this->digit($base);

        if ($cnpj !== $base.$first.$this->digit($base.$first)) {
            return null;
        }

        return sprintf('%s.%s.%s/%s-%s', substr($cnpj, 0, 2), substr($cnpj, 2, 3), substr($cnpj, 5, 3), substr($cnpj, 8, 4), substr($cnpj, 12, 2));
    }

    private function digit(string $base): int
    {
        $sum = 0;

        foreach (array_reverse(str_split($base)) as $i => $char) {
            $sum += (ord($char) - 48) * (($i % 8) + 2);
        }

        $rest = $sum % 11;

        return $rest < 2 ? 0 : 11 - $rest;
    }
}
