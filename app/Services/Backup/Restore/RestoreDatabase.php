<?php

namespace App\Services\Backup\Restore;

use App\Enums\ChannelStatus;
use App\Jobs\RecoverMessagesJob;
use App\Models\Channel;
use App\Notifications\BackupRestored;
use App\Services\Alerts\AlertRecipients;
use App\Services\Backup\BackupKind;
use App\Services\Backup\DumpFailed;
use App\Services\Backup\RunBackup;
use App\Settings\BackupStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * Restaura o banco ao lado e troca por renomeação (ver o spec). Antes da troca o sistema segue no ar e uma falha só apaga o
 * banco novo; a troca roda em manutenção e, se ela ou o `migrate` falharem, é desfeita. O estado nunca fica em manutenção.
 */
class RestoreDatabase
{
    /** Os passos de cada modo, na ordem: a tela usa os mesmos para mostrar o andamento antes de o job começar. */
    public const RESTORE_STEPS = ['inspect', 'safety', 'restore', 'verify', 'swap', 'migrate', 'resume', 'recover'];

    public const SWITCH_STEPS = ['swap', 'migrate', 'resume', 'recover'];

    public function __construct(
        private readonly DumpInspector $inspector,
        private readonly RestoreSource $sources,
        private readonly AdminDatabase $admin,
        private readonly SideDatabase $side,
        private readonly Maintenance $maintenance,
        private readonly RestoreProgress $progress,
        private readonly RunBackup $backup,
        private readonly AlertRecipients $recipients,
    ) {}

    /**
     * Falhas do processo viram estado `failed` no {@see RestoreProgress}; só o lock ocupado é lançado (a outra restauração é
     * a dona do andamento).
     *
     * @throws RestoreRejected
     */
    public function __invoke(RestoreRequest $request): void
    {
        $lock = Cache::lock('restore-database', 1800);

        if (! $lock->get()) {
            throw new RestoreRejected('Já há uma restauração em andamento.');
        }

        $run = new RestoreRun($request);

        try {
            $request->mode === 'switch' ? $this->switchBack($run) : $this->restore($run);
            $this->succeed($run);
        } catch (Throwable $e) {
            $this->fail($run, $e);
        } finally {
            $this->release($run, $lock);
        }
    }

    /** @throws Throwable */
    private function restore(RestoreRun $run): void
    {
        $this->progress->begin('Restaurando o banco', self::RESTORE_STEPS);

        $this->step('inspect', function () use ($run): void {
            $fetched = $this->sources->fetch($run->request->source);
            $run->path = $fetched['path'];
            $info = $this->inspector->inspect($fetched['path'], $fetched['name']);
            $run->label = $info->name;
            $run->from = $info->createdAt;
        });

        $this->step('safety', function (): void {
            if ($this->backup(BackupKind::Antes) === null) {
                throw new RestoreRejected('Há um backup em andamento. Tente de novo em instantes.');
            }
        });

        $now = CarbonImmutable::now();
        $run->active = $this->admin->active();
        $run->incoming = AdminDatabase::stagingName($run->active, $now);
        $run->archive = AdminDatabase::archiveName($run->active, $now);

        $this->step('restore', function () use ($run): void {
            $run->staging = $run->incoming;
            $this->admin->create($run->incoming);
            $this->side->restore($run->incoming, (string) $run->path);
        });

        $this->step('verify', fn () => $this->side->verify($run->incoming));

        $this->cutover($run);
    }

    /** @throws Throwable */
    private function switchBack(RestoreRun $run): void
    {
        $this->progress->begin('Voltando para o banco guardado', self::SWITCH_STEPS);

        $stored = collect($this->admin->stored())->firstWhere('name', $run->request->source);

        if ($stored === null) {
            throw new RestoreRejected('Esse banco guardado não existe mais.');
        }

        $now = CarbonImmutable::now();
        $run->active = $this->admin->active();
        $run->incoming = $stored->name;
        $run->archive = AdminDatabase::archiveName($run->active, $now);
        $run->label = $stored->name;
        $run->from = $stored->createdAt;

        $this->cutover($run);
    }

    /** @throws Throwable */
    private function cutover(RestoreRun $run): void
    {
        $this->step('swap', function () use ($run): void {
            $this->maintenance->enter();
            $run->inMaintenance = true;
            $this->admin->swap($run->active, $run->incoming, $run->archive);
            $run->swapped = true;
        });

        $this->step('migrate', fn () => $this->maintenance->migrate());

        $this->step('resume', function () use ($run): void {
            $this->maintenance->restartWorkers();
            $this->maintenance->leave();
            $run->inMaintenance = false;
        });

        $this->step('recover', fn () => $this->recoverMessages($run->from ?? CarbonImmutable::now()));
    }

    /** Um `RecoverMessagesJob` por canal ativo e conectado, do backup (no máximo `recover_hours` atrás) até agora. */
    private function recoverMessages(CarbonImmutable $backupAt): void
    {
        $now = CarbonImmutable::now();
        $floor = $now->subHours(config()->integer('backup.restore.recover_hours'));
        $from = $backupAt->greaterThan($floor) ? $backupAt : $floor;

        Channel::query()->where('active', true)->where('status', ChannelStatus::WORKING->value)->get()
            ->each(fn (Channel $channel) => RecoverMessagesJob::dispatch($channel->id, $from, $now, true));
    }

    private function succeed(RestoreRun $run): void
    {
        BackupStatus::recordRestore($run->label, $run->request->actor);
        Notification::send($this->recipients->admins(), new BackupRestored);

        $message = $run->request->mode === 'switch'
            ? 'O banco guardado voltou a ser o ativo. O banco que estava ativo ficou guardado.'
            : 'Restauração concluída. O banco anterior ficou guardado por '.config()->integer('backup.restore.keep_old_days').' dias.';
        $this->progress->finish($message);
        $this->log($run, 'ok', $message);
    }

    private function fail(RestoreRun $run, Throwable $e): void
    {
        $safe = $e instanceof InvalidDump || $e instanceof RestoreRejected || $e instanceof SwapFailed || $e instanceof DumpFailed;
        $message = $safe ? $e->getMessage() : 'Erro inesperado na restauração ('.class_basename($e).'). Veja o log do app.';
        $safe || report($e);

        try {
            $message = $this->undo($run, $message);
        } catch (Throwable $undoFailure) {
            report($undoFailure);
            $message .= ' Atenção: não foi possível desfazer a troca; confira o banco ativo.';
        }

        $this->progress->fail($message);
        $this->log($run, 'falhou', $message);
    }

    /** Depois da troca, volta ao banco anterior; apaga o banco novo criado para esta restauração. */
    private function undo(RestoreRun $run, string $message): string
    {
        if ($run->swapped) {
            $this->admin->swap($run->active, $run->archive, $run->incoming);
            $run->swapped = false;
            $message .= ' A troca foi desfeita e o banco anterior continua ativo.';
        }

        if ($run->staging !== null && $this->admin->exists($run->staging)) {
            $this->admin->drop($run->staging);
        }

        return $message;
    }

    private function release(RestoreRun $run, Lock $lock): void
    {
        try {
            $run->inMaintenance && $this->maintenance->leave();
        } catch (Throwable $e) {
            report($e);
        }

        // No `switch` a origem é o nome de um banco, não um arquivo: não há o que descartar.
        $run->request->mode === 'switch' || $this->sources->discard($run->request->source, $run->path);
        $lock->release();
    }

    /** Marca o passo, roda e marca como feito; a exceção sobe para o `fail` (que marca o passo como falho). */
    private function step(string $key, callable $work): void
    {
        $this->progress->running($key);
        $work();
        $this->progress->done($key);
    }

    private function backup(BackupKind $kind): mixed
    {
        return ($this->backup)($kind);
    }

    private function log(RestoreRun $run, string $result, string $message): void
    {
        Log::notice('Restauração do banco', [
            'mode' => $run->request->mode,
            'source' => $run->request->source,
            'actor' => $run->request->actor,
            'result' => $result,
            'message' => $message,
        ]);
    }
}
