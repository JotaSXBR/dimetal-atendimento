<?php

namespace App\Console\Commands;

use App\Services\Backup\BackupKind;
use App\Services\Backup\DumpFailed;
use App\Services\Backup\ManualBackupState;
use App\Services\Backup\Restore\RestoreProgress;
use App\Services\Backup\RunBackup;
use Illuminate\Console\Command;

class BackupDatabaseCommand extends Command
{
    protected $signature = 'app:backup-database {--manual : Backup manual (guarda os 3 últimos, à parte dos diários)}';

    protected $description = 'Faz o backup do banco (pg_dump) para o bucket de backups e aplica a retenção';

    public function handle(RunBackup $backup, ManualBackupState $state): int
    {
        $manual = (bool) $this->option('manual');

        // Um backup durante a restauração pegaria o `pg_terminate_backend` da troca e falharia.
        if (app(RestoreProgress::class)->isRunning()) {
            $manual && $state->markFinished(null, 'Há uma restauração em andamento; tente de novo quando ela terminar.');
            $this->warn('Há uma restauração em andamento; o backup espera.');

            return self::SUCCESS;
        }

        try {
            $file = $backup($manual ? BackupKind::Manual : BackupKind::Diario);
        } catch (DumpFailed $e) {
            $manual && $state->markFinished(null, $e->getMessage());
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($file === null) {
            $manual && $state->markFinished(null, 'Já havia um backup em andamento.');
            $this->warn('Já há um backup em andamento.');

            return self::SUCCESS;
        }

        $manual && $state->markFinished($file->name, null);
        $this->info(sprintf('Backup gravado: %s (%s MB).', $file->name, number_format($file->size / 1_000_000, 1, ',', '.')));

        return self::SUCCESS;
    }
}
