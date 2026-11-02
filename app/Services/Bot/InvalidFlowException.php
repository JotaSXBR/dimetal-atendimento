<?php

namespace App\Services\Bot;

use RuntimeException;

/** O grafo não pode ser publicado; `$errors` traz uma mensagem por problema, começando pelo id do bloco. */
final class InvalidFlowException extends RuntimeException
{
    /** @param  list<string>  $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('O workflow tem erros: '.implode(' ', $errors));
    }
}
