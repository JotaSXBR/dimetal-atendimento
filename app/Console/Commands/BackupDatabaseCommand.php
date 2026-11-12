<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupKind;
use App\Services\Backup\DumpFailed;
use App\Services\Backup\RunBackup;
use Illuminate\Console\Command;

class BackupDatabaseCommand extends Command
{
    protected $signature = 'app:backup-database {--manual : Backup manual (guarda os 3 últimos, à parte dos diários)}';

    protected $description = 'Faz o backup do banco (pg_dump) para o bucket de backups e aplica a retenção';

    public function handle(RunBackup $backup): int
    {
        try {
            $file = $backup($this->option('manual') ? BackupKind::Manual : BackupKind::Diario);
        } catch (DumpFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($file === null) {
            $this->warn('Já há um backup em andamento.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Backup gravado: %s (%s MB).', $file->name, number_format($file->size / 1_000_000, 1, ',', '.')));

        return self::SUCCESS;
    }
}
