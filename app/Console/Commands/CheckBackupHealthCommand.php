<?php

namespace App\Console\Commands;

use App\Notifications\BackupStale;
use App\Services\Alerts\AlertRecipients;
use App\Settings\BackupStatus;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class CheckBackupHealthCommand extends Command
{
    /** Um aviso de atraso por dia, no máximo: repetir de hora em hora viraria ruído. */
    private const REALERT_HOURS = 24;

    protected $signature = 'app:check-backup-health';

    protected $description = 'Avisa os admins quando passa de 26 h sem um backup bom do banco';

    public function handle(AlertRecipients $recipients): int
    {
        $last = BackupStatus::lastSuccessAt();

        if ($last !== null && $last->gt(now()->subHours(config()->integer('backup.stale_hours')))) {
            return self::SUCCESS;
        }

        $alerted = BackupStatus::staleAlertedAt();

        if ($alerted !== null && $alerted->gt(now()->subHours(self::REALERT_HOURS))) {
            return self::SUCCESS;
        }

        Notification::send($recipients->admins(), new BackupStale);
        BackupStatus::markStaleAlerted();
        $this->warn('Backup atrasado: os admins foram avisados.');

        return self::SUCCESS;
    }
}
