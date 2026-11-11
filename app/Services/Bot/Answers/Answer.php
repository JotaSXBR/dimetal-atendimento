<?php

namespace App\Services\Bot\Answers;

/** Um tipo de resposta que a Pergunta pode exigir (CNPJ, CPF...). */
interface Answer
{
    /** A resposta já no formato guardado, ou `null` quando o texto não é um valor válido deste tipo. */
    public function parse(string $text): ?string;
}
