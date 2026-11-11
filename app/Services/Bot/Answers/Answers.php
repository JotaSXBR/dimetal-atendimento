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

        return $answer !== null ? $answer->parse($text) : trim($text);
    }

    /** @return list<string> os tipos que passam por aqui (o editor oferece estes além de texto, e-mail e número) */
    public static function kinds(): array
    {
        return ['cnpj', 'cpf', 'cep', 'telefone', 'data'];
    }
}
