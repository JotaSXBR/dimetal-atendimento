<?php

namespace App\Services\Bot;

/**
 * Lê a resposta do cliente a um menu: o número escolhido e o pedido de falar com um atendente. Quem escreve por um sistema que
 * acrescenta assinatura ("*#Jessé:*" e o texto na linha de baixo) continua entendido: o que vale é a linha que é só o número.
 */
final class ChoiceReply
{
    /** O número escolhido, ou nulo quando não há escolha clara (nenhuma linha só de número, ou números diferentes). */
    public static function number(string $text): ?int
    {
        $lines = self::lines($text);
        $numbers = array_values(array_unique(array_map(intval(...), array_filter($lines, fn (string $line): bool => preg_match('/^\d{1,3}$/', $line) === 1))));

        return count($numbers) === 1 ? $numbers[0] : null;
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
