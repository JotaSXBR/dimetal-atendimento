<?php

namespace App\Enums;

/** De quem é a etiqueta: do contato (quem é o cliente; segue o cliente entre atendimentos) ou da conversa (assunto do atendimento). */
enum TagScope: string
{
    case CONTATO = 'contato';
    case CONVERSA = 'conversa';

    public function label(): string
    {
        return match ($this) {
            self::CONTATO => 'Do contato',
            self::CONVERSA => 'Da conversa',
        };
    }
}
