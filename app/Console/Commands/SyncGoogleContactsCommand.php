<?php

namespace App\Console\Commands;

use App\Actions\Contacts\SyncPendingContacts;
use Illuminate\Console\Command;

class SyncGoogleContactsCommand extends Command
{
    protected $signature = 'app:sync-google-contacts';

    protected $description = 'Enfileira o envio ao Google dos contatos editados que ainda não foram enviados (ou mudaram depois do último envio)';

    public function handle(SyncPendingContacts $syncPending): int
    {
        $count = $syncPending();

        $this->info($count.' '.($count === 1 ? 'contato enfileirado' : 'contatos enfileirados').' para o Google.');

        return self::SUCCESS;
    }
}
