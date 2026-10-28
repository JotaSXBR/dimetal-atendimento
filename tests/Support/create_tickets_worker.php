<?php

/**
 * Processo separado do TicketProtocolConcurrencyTest: abre um ticket por contato no instante combinado, para as
 * conexões chegarem ao contador do protocolo ao mesmo tempo.
 * Uso: php create_tickets_worker.php <channel_id> <contact_ids_separados_por_virgula> <start_epoch_float>
 */

use App\Models\Ticket;
use Illuminate\Contracts\Console\Kernel;

[, $channelId, $contactIds, $startAt] = $argv;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$wait = (float) $startAt - microtime(true);

if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}

foreach (explode(',', $contactIds) as $contactId) {
    Ticket::create([
        'contact_id' => (int) $contactId,
        'channel_id' => (int) $channelId,
        'status' => 'aguardando',
        'opened_at' => now(),
    ]);
}

echo 'db='.config('database.connections.pgsql.database');
