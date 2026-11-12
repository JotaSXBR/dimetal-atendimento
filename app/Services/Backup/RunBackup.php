<?php

namespace App\Services\Backup;

use App\Notifications\BackupFailed;
use App\Services\Alerts\AlertRecipients;
use App\Settings\BackupStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Throwable;

/** Um backup completo: dump, envio ao bucket, cópia semanal (domingo), rotação, estado e alerta de falha. */
class RunBackup
{
    public function __construct(
        private readonly DatabaseDumper $dumper,
        private readonly BackupCatalog $catalog,
        private readonly AlertRecipients $recipients,
    ) {}

    /**
     * `null` quando já há um backup em andamento (nada é feito).
     *
     * @throws DumpFailed
     */
    public function __invoke(BackupKind $kind): ?BackupFile
    {
        $lock = Cache::lock('backup-database', 1800);

        if (! $lock->get()) {
            return null;
        }

        $temporary = null;

        try {
            $temporary = $this->dumper->dump();
            $file = $this->store($temporary, $kind, CarbonImmutable::now());
            BackupStatus::recordSuccess();
            $this->prune();

            return $file;
        } catch (Throwable $e) {
            $failure = $e instanceof DumpFailed ? $e : new DumpFailed('Falha ao gravar o backup no bucket ('.class_basename($e).').', 0, $e);
            $this->reportFailure($failure);

            throw $failure;
        } finally {
            $temporary === null || File::delete($temporary);
            $lock->release();
        }
    }

    /** Grava o erro e avisa os admins; com o banco fora do ar nada disso grava, e o motivo do dump não pode se perder por isso. */
    private function reportFailure(DumpFailed $failure): void
    {
        try {
            BackupStatus::recordFailure($failure->getMessage());
            Notification::send($this->recipients->admins(), new BackupFailed);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Envia o dump em stream; no domingo (fuso de exibição) o diário ganha também uma cópia semanal. O arquivo devolvido é montado aqui, sem reler o bucket. */
    private function store(string $temporary, BackupKind $kind, CarbonImmutable $at): BackupFile
    {
        $disk = $this->catalog->disk();
        $name = $this->catalog->name($kind, $at);
        $stream = fopen($temporary, 'rb');

        try {
            if ($stream === false || ! $disk->writeStream($name, $stream)) {
                throw new DumpFailed('Não foi possível gravar o backup no bucket.');
            }
        } finally {
            is_resource($stream) && fclose($stream);
        }

        $local = $at->setTimezone(config()->string('app.display_timezone'));

        // A cópia semanal que falha não derruba o diário, que já está gravado: só aparece no log.
        if ($kind === BackupKind::Diario && $local->isSunday() && ! $disk->copy($name, $this->catalog->name(BackupKind::Semanal, $at))) {
            report(new DumpFailed('A cópia semanal não foi gravada; o backup diário está salvo.'));
        }

        return new BackupFile($name, $kind, $local->startOfMinute(), (int) filesize($temporary));
    }

    /** A rotação não derruba um backup que já foi gravado: se falhar, só vai para o log. */
    private function prune(): void
    {
        try {
            $this->catalog->prune();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
