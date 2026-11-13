<?php

namespace App\Services\Backup\Restore;

use App\Enums\AuditAction;
use App\Enums\ChannelStatus;
use App\Jobs\RecoverMessagesJob;
use App\Models\Channel;
use App\Notifications\BackupRestored;
use App\Services\Alerts\AlertRecipients;
use App\Services\Audit\Auditor;
use App\Services\Backup\BackupKind;
use App\Services\Backup\DumpFailed;
use App\Services\Backup\RunBackup;
use App\Settings\BackupStatus;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
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
        $this->assertReady();
        $now = CarbonImmutable::now();
        $run->active = $this->admin->active();
        $run->incoming = AdminDatabase::stagingName($run->active, $now);
        $run->archive = $this->freeArchiveName($run->active, $now, $run->incoming);
        $this->assertNames($run);

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
        $this->assertReady();

        $stored = collect($this->admin->stored())->firstWhere('name', $run->request->source);

        if ($stored === null) {
            throw new RestoreRejected('Esse banco guardado não existe mais.');
        }

        $now = CarbonImmutable::now();
        $run->active = $this->admin->active();
        $run->incoming = $stored->name;
        $run->archive = $this->freeArchiveName($run->active, $now, $run->incoming);
        $run->label = $stored->name;
        $run->from = $stored->createdAt;
        $this->assertNames($run);

        $this->cutover($run);
    }

    /**
     * O nome do banco guardado leva o minuto da troca; se já existe (duas restaurações no mesmo minuto, ou voltar logo depois de
     * restaurar, quando o banco a que se volta tem justamente o nome deste minuto), avança um minuto.
     */
    private function freeArchiveName(string $active, CarbonImmutable $at, string $incoming): string
    {
        $name = AdminDatabase::archiveName($active, $at);

        for ($tries = 0; $tries < 60 && ($name === $incoming || $this->admin->exists($name)); $tries++) {
            $at = $at->addMinute();
            $name = AdminDatabase::archiveName($active, $at);
        }

        return $name;
    }

    /** @throws Throwable */
    private function cutover(RestoreRun $run): void
    {
        $this->assertWithinDeadline($run);

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
            // Daqui o sistema grava no banco novo: uma falha nos passos seguintes não pode desfazer a troca.
            $run->swapped = false;
            $run->committed = true;
        });

        $this->step('recover', function () use ($run): void {
            $this->bestEffort($run, 'A recuperação das mensagens do WhatsApp falhou; use a recuperação manual em cada canal.', fn () => $this->recoverMessages($run->from ?? CarbonImmutable::now()));
        });
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
        $this->bestEffort($run, 'O registro da restauração ou o aviso aos administradores não pôde ser gravado.', function () use ($run): void {
            BackupStatus::recordRestore($run->label, $run->request->actor);
            Notification::send($this->recipients->admins(), new BackupRestored);
        });

        $message = $run->request->mode === 'switch'
            ? 'O banco guardado voltou a ser o ativo. O banco que estava ativo ficou guardado.'
            : 'Restauração concluída. O banco anterior ficou guardado por '.config()->integer('backup.restore.keep_old_days').' dias.';
        if ($run->warnings !== []) {
            $message .= ' Atenção: '.implode(' ', $run->warnings);
        }

        $this->progress->finish($message);
        $this->log($run, 'ok', $message);
    }

    private function fail(RestoreRun $run, Throwable $e): void
    {
        $message = $this->publicMessage($e);

        try {
            $message = $this->undo($run, $message);
        } catch (Throwable $undoFailure) {
            report($undoFailure);
            // Sem volta: o sistema fica em manutenção (ninguém grava num banco meio migrado) até o admin conferir.
            $run->keepDown = true;
            $message .= ' Atenção: não foi possível desfazer a troca; o sistema foi deixado em manutenção. Confira o banco ativo e rode `php artisan up` quando estiver certo.';
        }

        $this->progress->fail($message);
        $this->log($run, 'falhou', $message);
    }

    /**
     * O texto que a página de andamento (pública) mostra. Mensagens de processo (`pg_dump`, `pg_restore`) podem trazer host, usuário ou
     * linhas de tabela no stderr: ficam só no log.
     */
    private function publicMessage(Throwable $e): string
    {
        if ($e instanceof DumpFailed) {
            report($e);

            return 'O backup de segurança falhou, então nada foi trocado. O detalhe está no log do app.';
        }

        if ($e instanceof InvalidDump || $e instanceof RestoreRejected || $e instanceof SwapFailed) {
            return $e->getMessage();
        }

        report($e);

        return 'Erro inesperado na restauração ('.class_basename($e).'). Veja o log do app.';
    }

    /** @throws RestoreRejected */
    private function assertReady(): void
    {
        $problem = $this->maintenance->problem();

        if ($problem !== null) {
            throw new RestoreRejected($problem);
        }
    }

    /** Os nomes dos bancos novos cabem nas regras (senão o erro só apareceria depois do download e do backup de segurança). */
    private function assertNames(RestoreRun $run): void
    {
        try {
            AdminDatabase::assertName($run->incoming);
            AdminDatabase::assertName($run->archive);
        } catch (InvalidArgumentException) {
            throw new RestoreRejected('O nome do banco ativo não permite criar o banco de restauração (só letras minúsculas, números e _, até 38 caracteres).');
        }
    }

    /** @throws RestoreRejected */
    private function assertWithinDeadline(RestoreRun $run): void
    {
        $elapsed = CarbonImmutable::now()->getTimestamp() - $run->startedAt->getTimestamp();

        if ($elapsed > config()->integer('backup.restore.cutover_deadline')) {
            throw new RestoreRejected('A restauração passou do tempo seguro antes da troca e foi cancelada; o banco atual continua ativo. Tente de novo ou use o roteiro manual para bancos muito grandes.');
        }
    }

    /** Passo que roda depois de a restauração já ter dado certo: se falhar vira aviso, não desfaz a troca. */
    private function bestEffort(RestoreRun $run, string $warning, callable $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            report($e);
            $run->warnings[] = $warning;
        }
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
            $run->inMaintenance && ! $run->keepDown && $this->maintenance->leave();
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

        // `$message` é o texto público que a página de andamento já mostra (sem a saída bruta do processo).
        $subject = $run->label !== '' ? $run->label : $run->request->source;
        Auditor::record($result === 'ok' ? AuditAction::RestoreSucceeded : AuditAction::RestoreFailed, null, $subject, ['mode' => $run->request->mode, 'message' => $message], $run->request->actor);
    }
}
