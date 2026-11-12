<?php

namespace App\Console\Commands;

use App\Services\Backup\Restore\AdminDatabase;
use App\Services\Backup\Restore\RestoreSource;
use Illuminate\Console\Command;

class PurgeOldDatabasesCommand extends Command
{
    protected $signature = 'app:purge-old-databases';

    protected $description = 'Apaga os bancos guardados por uma restauração (<db>_antes_...) com mais de 7 dias, bancos de restauração órfãos e arquivos enviados abandonados';

    /** Um banco `_restaurando_` com mais que isto é de um job morto no meio (a restauração inteira cabe em 15 minutos). */
    private const ORPHAN_HOURS = 24;

    public function handle(AdminDatabase $admin, RestoreSource $sources): int
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

        $orphans = 0;

        foreach ($admin->staging() as $database) {
            if ($database->createdAt->lessThan(now()->subHours(self::ORPHAN_HOURS))) {
                $admin->drop($database->name);
                $orphans++;
            }
        }

        $this->info("Bancos de restauração órfãos apagados: {$orphans}");
        $this->info('Arquivos enviados abandonados apagados: '.$sources->sweepUploads());

        return self::SUCCESS;
    }
}
