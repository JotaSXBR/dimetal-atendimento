<?php

namespace App\Services\Bot;

/**
 * Lê a resposta do cliente a um menu: o número escolhido e o pedido de falar com um atendente. Quem escreve por um sistema que
 * acrescenta assinatura ("*#Jessé:*" e o texto na linha de baixo) continua entendido: o que vale é a linha que é só o número.
 */
final class ChoiceReply
{
    /**
     * O número escolhido, ou nulo quando não há escolha clara (nenhuma linha só de número, ou números diferentes). A linha é o
     * número sozinho, com ponto, parêntese ou exclamação no fim, ou precedido de "opção", "número" ou "nº" ("Apto 12" não vale).
     */
    public static function number(string $text): ?int
    {
        $lines = self::lines($text);
        $numbers = [];

        foreach ($lines as $line) {
            if (preg_match('/^(?:op[çc][aã]o|n[úu]mero|n[º°o]\.?)?\s*(\d{1,3})\s*[.)!]?$/iu', $line, $m) === 1) {
                $numbers[(int) $m[1]] = true;
            }
        }

        return count($numbers) === 1 ? array_key_first($numbers) : null;
    }

    /** `#sair` sozinho em qualquer linha da mensagem (sem diferenciar maiúscula). */
    public static function wantsOut(string $text): bool
    {
        foreach (self::lines($text) as $line) {
            if (mb_strtolower($line) === '#sair') {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> as linhas aparadas, sem as vazias */
    private static function lines(string $text): array
    {
        return array_values(array_filter(array_map(trim(...), preg_split('/\R/u', $text) ?: []), fn (string $line): bool => $line !== ''));
    }
}
