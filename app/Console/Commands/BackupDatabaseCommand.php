<?php

namespace App\Console\Commands;

use App\Enums\UpdateStatus;
use App\Models\SystemUpdate;
use App\Notifications\BackupSkipped;
use App\Services\Alerts\AlertRecipients;
use App\Services\Backup\BackupKind;
use App\Services\Backup\DumpFailed;
use App\Services\Backup\ManualBackupState;
use App\Services\Backup\Restore\RestoreProgress;
use App\Services\Backup\RunBackup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class BackupDatabaseCommand extends Command
{
    protected $signature = 'app:backup-database {--manual : Backup manual (guarda os 3 últimos, à parte dos diários)}';

    protected $description = 'Faz o backup do banco (pg_dump) para o bucket de backups e aplica a retenção';

    public function handle(RunBackup $backup, ManualBackupState $state, AlertRecipients $recipients): int
    {
        $manual = (bool) $this->option('manual');

        if ($this->mustWait($manual, $state)) {
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
            $manual || Notification::send($recipients->admins(), new BackupSkipped);
            $this->warn('Já há um backup em andamento.');

            return self::SUCCESS;
        }

        $manual && $state->markFinished($file->name, null);
        $this->info(sprintf('Backup gravado: %s (%s MB).', $file->name, number_format($file->size / 1_000_000, 1, ',', '.')));

        return self::SUCCESS;
    }

    /** Restauração ou atualização em curso: o backup espera (o manual avisa a tela; o diário só avisa no console). */
    private function mustWait(bool $manual, ManualBackupState $state): bool
    {
        // Um backup durante a restauração pegaria o `pg_terminate_backend` da troca e falharia.
        if (app(RestoreProgress::class)->isRunning()) {
            $manual && $state->markFinished(null, 'Há uma restauração em andamento; tente de novo quando ela terminar.');
            $this->warn('Há uma restauração em andamento; o backup espera.');

            return true;
        }

        // A atualização fez o próprio backup minutos antes; o diário no meio dela só disputaria o banco.
        if (! $manual && in_array(SystemUpdate::open()?->status, [UpdateStatus::Aplicando, UpdateStatus::Reiniciando], true)) {
            $this->warn('Há uma atualização do sistema em andamento; o backup diário foi pulado.');

            return true;
        }

        return false;
    }
}
