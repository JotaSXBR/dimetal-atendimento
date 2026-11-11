<?php

namespace App\Services\Bot\Answers;

/** Escolhe o tipo de resposta pelo nome gravado no bloco Pergunta (`validacao`). Nome desconhecido vale o texto aparado. */
final class Answers
{
    public static function parse(string $kind, string $text): ?string
    {
        $answer = match ($kind) {
            'cnpj' => new Cnpj,
            'cpf' => new Cpf,
            'cep' => new Cep,
            'telefone' => new Telefone,
            'data' => new Data,
            default => null,
        };

        return $answer !== null ? self::find($answer, $text) : trim($text);
    }

    /**
     * O texto inteiro, senão cada linha (cliente cujo sistema acrescenta assinatura), senão cada palavra ("meu cnpj é ..."):
     * vale o primeiro pedaço que é um valor válido.
     */
    private static function find(Answer $answer, string $text): ?string
    {
        foreach ([[$text], preg_split('/\R/u', $text) ?: [], preg_split('/\s+/u', $text) ?: []] as $pieces) {
            foreach ($pieces as $piece) {
                $value = $answer->parse(trim($piece));

                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    /** @return list<string> os tipos que passam por aqui (o editor oferece estes além de texto, e-mail e número) */
    public static function kinds(): array
    {
        return ['cnpj', 'cpf', 'cep', 'telefone', 'data'];
    }
}
