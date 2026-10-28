<?php

/**
 * Processo separado do ClaimConcurrencyTest: assume o ticket no instante combinado, para as duas conexões
 * chegarem ao banco ao mesmo tempo. Uso: php claim_worker.php <ticket_id> <user_id> <start_epoch_float>
 */

use App\Actions\Inbox\ClaimTicket;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

[, $ticketId, $userId, $startAt] = $argv;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$wait = (float) $startAt - microtime(true);

if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}

$won = (new ClaimTicket)(Ticket::findOrFail((int) $ticketId), User::findOrFail((int) $userId));

echo ($won ? 'WON' : 'LOST').' db='.config('database.connections.pgsql.database');
