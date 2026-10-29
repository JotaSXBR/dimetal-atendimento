<?php

namespace App\Enums;

enum MessageOrigin: string
{
    case CONTATO = 'contato';
    case PAINEL = 'painel';
    /** Resposta automática do sistema (fora do horário hoje, o bot depois): sem autor e sem contar como resposta humana. */
    case BOT = 'bot';
    case FORA_DO_PAINEL = 'fora_do_painel';
}
