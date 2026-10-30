<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

class PurgeNotificationsCommand extends Command
{
    /** O sino guarda o histórico por 7 dias. */
    public const RETENTION_DAYS = 7;

    protected $signature = 'app:purge-notifications';

    protected $description = 'Apaga as notificações do sino com mais de 7 dias';

    public function handle(): int
    {
        $deleted = DatabaseNotification::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS))->delete();

        $this->info("Notificações apagadas: {$deleted}.");

        return self::SUCCESS;
    }
}
