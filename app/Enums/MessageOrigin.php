<?php

namespace App\Enums;

enum MessageOrigin: string
{
    case CONTATO = 'contato';
    case PAINEL = 'painel';
    case FORA_DO_PAINEL = 'fora_do_painel';
}
