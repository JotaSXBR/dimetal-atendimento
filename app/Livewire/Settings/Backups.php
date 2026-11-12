<?php

namespace App\Livewire\Settings;

use App\Jobs\RestoreDatabaseJob;
use App\Services\Backup\BackupCatalog;
use App\Services\Backup\BackupFile;
use App\Services\Backup\ManualBackupState;
use App\Services\Backup\Restore\AdminDatabase;
use App\Services\Backup\Restore\DumpInfo;
use App\Services\Backup\Restore\DumpInspector;
use App\Services\Backup\Restore\InvalidDump;
use App\Services\Backup\Restore\Maintenance;
use App\Services\Backup\Restore\RestoreDatabase;
use App\Services\Backup\Restore\RestoreProgress;
use App\Services\Backup\Restore\RestoreRequest;
use App\Services\Backup\Restore\RestoreSource;
use App\Services\Backup\Restore\StoredDatabase;
use App\Settings\BackupStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

#[Title('Backup do banco')]
class Backups extends Component
{
    use WithFileUploads;

    /** O arquivo enviado do computador (upload temporário do Livewire). */
    public mixed $upload = null;

    /** O que será restaurado: `bucket:<nome>` ou `upload:<ulid>`. Só o servidor define (a tela não pode forjar). */
    #[Locked]
    public ?string $source = null;

    /** O banco guardado a que se volta (`<db>_antes_...`), só definido por {@see self::chooseStored()}. */
    #[Locked]
    public ?string $target = null;

    /** @var array<string, string> o resumo mostrado na conferência */
    #[Locked]
    public array $info = [];

    public string $confirm = '';

    public ?string $problem = null;

    public function mount(): void
    {
        $this->authorize('admin');
    }

    /** Enfileira o mesmo backup da rotina diária, como manual (um dump pode levar minutos: não roda no request). */
    public function runNow(ManualBackupState $state): void
    {
        $this->authorize('admin');

        if ($state->isRunning()) {
            $this->problem = 'Já há um backup em andamento.';

            return;
        }

        $this->problem = null;
        $state->markStarted();

        // Fila `history`: o worker dela aguenta até 900 s; na `default` (120 s) um dump longo mataria o worker e as outras filas.
        Artisan::queue('app:backup-database', ['--manual' => true])->onQueue('history');
    }

    /** Abre a conferência de um backup da lista. */
    public function chooseBackup(string $name): void
    {
        $this->authorize('admin');
        $this->open('bucket:'.$name);
    }

    /** O arquivo enviado do computador vai para o bucket (em `restauracao/`, fora da lista de backups) e abre a conferência. */
    public function updatedUpload(): void
    {
        $this->authorize('admin');

        $this->validate([
            'upload' => ['required', 'file', 'extensions:dump', 'max:'.(config()->integer('backup.restore.upload_max_mb') * 1024)],
        ], [
            'upload.extensions' => 'O arquivo precisa ter a extensão .dump (backup do PostgreSQL).',
            'upload.max' => 'O arquivo passa do limite de '.config()->integer('backup.restore.upload_max_mb').' MB.',
        ]);

        $path = $this->upload instanceof UploadedFile ? $this->upload->getRealPath() : false;
        $this->upload = null;

        if ($path === false) {
            $this->problem = 'Não foi possível ler o arquivo enviado.';

            return;
        }

        try {
            $this->open(app(RestoreSource::class)->storeUpload($path));
        } catch (InvalidDump $e) {
            $this->problem = $e->getMessage();
        }
    }

    /** Abre a conferência de um banco guardado para voltar a ele. */
    public function chooseStored(string $name): void
    {
        $this->authorize('admin');
        $this->cancelRestore();

        $stored = collect($this->storedDatabases())->firstWhere('name', $name);

        if (! $stored instanceof StoredDatabase) {
            $this->problem = 'Esse banco guardado não existe mais.';

            return;
        }

        $this->target = $stored->name;
        $this->info = [
            'name' => $stored->name,
            'created' => $stored->createdAt->format('d/m/Y H:i'),
            'size' => $this->megabytes($stored->bytes),
        ];
    }

    public function cancelRestore(): void
    {
        $this->authorize('admin');

        if ($this->source !== null) {
            app(RestoreSource::class)->discard($this->source);
        }

        $this->reset('source', 'target', 'info', 'confirm', 'problem');
    }

    public function restore(): void
    {
        $this->authorize('admin');

        if ($this->source === null) {
            $this->problem = 'Escolha um backup para restaurar.';

            return;
        }

        $this->start(new RestoreRequest('restore', $this->source, $this->actor()), RestoreDatabase::RESTORE_STEPS, 'Restaurando o banco');
    }

    public function switchBack(): void
    {
        $this->authorize('admin');

        if ($this->target === null) {
            $this->problem = 'Escolha um banco guardado para voltar a ele.';

            return;
        }

        $this->start(new RestoreRequest('switch', $this->target, $this->actor()), RestoreDatabase::SWITCH_STEPS, 'Voltando para o banco guardado');
    }

    public function render(BackupCatalog $catalog): View
    {
        $timezone = config()->string('app.display_timezone');
        $files = [];
        $unreadable = false;

        try {
            $files = $catalog->all();
        } catch (Throwable $e) {
            // Bucket inexistente ou chave errada: a tela é onde o admin diagnostica, então ela avisa em vez de dar erro 500.
            report($e);
            $unreadable = true;
        }

        $restoring = app(RestoreProgress::class)->isRunning();
        $lastRestore = BackupStatus::lastRestore();

        return view('livewire.settings.backups', [
            'files' => $files,
            'unreadable' => $unreadable,
            'bytes' => array_sum(array_map(fn (BackupFile $file): int => $file->size, $files)),
            'lastSuccess' => BackupStatus::lastSuccessAt()?->setTimezone($timezone),
            'lastError' => BackupStatus::lastError(),
            'keep' => config('backup.keep'),
            'hour' => config()->string('backup.hour'),
            'stored' => $this->storedDatabases(),
            'manual' => app(ManualBackupState::class)->get(),
            'restoring' => $restoring,
            'lastRestore' => $lastRestore === null ? null : [...$lastRestore, 'at' => $lastRestore['at']->setTimezone($timezone)],
            'confirmWord' => config()->string('backup.restore.confirm_word'),
            'uploadMaxMb' => config()->integer('backup.restore.upload_max_mb'),
        ]);
    }

    /** Lê o arquivo (cópia temporária, cabeçalho, lista de permissão) e abre a conferência; o enviado inválido não fica no bucket. */
    private function open(string $source): void
    {
        $this->cancelRestore();
        $sources = app(RestoreSource::class);
        $path = null;

        try {
            $fetched = $sources->fetch($source);
            $path = $fetched['path'];
            $info = app(DumpInspector::class)->inspect($path, $fetched['name']);
            $this->source = $source;
            $this->info = $this->describe($info);
        } catch (InvalidDump $e) {
            $this->problem = $e->getMessage();
            $sources->discard($source);
        } finally {
            $path === null || $sources->discardTemporary($path);
        }
    }

    /**
     * As verificações que a tela faz antes de despachar (a palavra de confirmação, nada em andamento, o ambiente pronto).
     *
     * @param  list<string>  $steps
     */
    private function start(RestoreRequest $request, array $steps, string $title): void
    {
        $this->problem = $this->blocker();

        if ($this->problem !== null) {
            return;
        }

        // O andamento já aparece na página de acompanhamento antes de o worker pegar o job.
        app(RestoreProgress::class)->begin($title, $steps);
        RestoreDatabaseJob::dispatch($request);

        $this->redirect(route('restauracao'));
    }

    private function blocker(): ?string
    {
        if ($this->confirm !== config()->string('backup.restore.confirm_word')) {
            return 'Digite '.config()->string('backup.restore.confirm_word').' para confirmar.';
        }

        if (app(RestoreProgress::class)->isRunning()) {
            return 'Já há uma restauração em andamento.';
        }

        if (! app(Maintenance::class)->ready()) {
            return 'A restauração pela tela precisa do modo de manutenção compartilhado: defina APP_MAINTENANCE_DRIVER=cache e APP_MAINTENANCE_STORE=redis no app, no worker e no scheduler.';
        }

        try {
            return app(AdminDatabase::class)->canCreate() ? null : 'O usuário do banco não pode criar bancos (precisa ser superusuário ou ter CREATEDB).';
        } catch (Throwable $e) {
            report($e);

            return 'Não foi possível conectar ao banco de administração (`postgres`) com o usuário do app.';
        }
    }

    /** @return list<StoredDatabase> */
    private function storedDatabases(): array
    {
        try {
            return app(AdminDatabase::class)->stored();
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string, string> */
    private function describe(DumpInfo $info): array
    {
        return [
            'name' => $info->name,
            'created' => $info->createdAt->setTimezone(config()->string('app.display_timezone'))->format('d/m/Y H:i'),
            'database' => $info->database,
            'server' => $info->serverVersion,
            'dump' => $info->dumpVersion,
            'compression' => $info->compression,
            'size' => $this->megabytes($info->size),
            'entries' => (string) $info->entries,
        ];
    }

    private function megabytes(int $bytes): string
    {
        return number_format($bytes / 1_000_000, 1, ',', '.').' MB';
    }

    private function actor(): string
    {
        $user = Auth::user();

        return $user === null ? 'desconhecido' : $user->name.' <'.$user->email.'>';
    }
}
