<?php

namespace App\Services\Inbox;

/** De onde a mensagem veio: do webhook (ao vivo), de uma recuperação após queda ou da importação inicial. */
enum IngestMode: string
{
    case LIVE = 'live';
    case RECOVERED = 'recovered';
    case IMPORTED = 'imported';
}
