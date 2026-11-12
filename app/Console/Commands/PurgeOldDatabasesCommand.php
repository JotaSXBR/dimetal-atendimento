<?php

namespace App\Console\Commands;

use App\Services\Backup\Restore\AdminDatabase;
use Illuminate\Console\Command;

class PurgeOldDatabasesCommand extends Command
{
    protected $signature = 'app:purge-old-databases';

    protected $description = 'Apaga os bancos guardados por uma restauração (<db>_antes_...) com mais de 7 dias';

    public function handle(AdminDatabase $admin): int
    {
        $limit = now()->subDays(config()->integer('backup.restore.keep_old_days'));
        $dropped = 0;

        // `stored()` só lista `<ativo>_antes_AAAAMMDDHHMM` e `drop()` só apaga esse padrão: o banco ativo nunca entra.
        foreach ($admin->stored() as $database) {
            if ($database->createdAt->lessThan($limit)) {
                $admin->drop($database->name);
                $dropped++;
            }
        }

        $this->info("Bancos guardados apagados: {$dropped}");

        return self::SUCCESS;
    }
}
