<?php

namespace App\Livewire\Settings;

use App\Services\Backup\BackupCatalog;
use App\Services\Backup\BackupFile;
use App\Settings\BackupStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

#[Title('Backup do banco')]
class Backups extends Component
{
    public string $result = '';

    public function mount(): void
    {
        $this->authorize('admin');
    }

    /** Enfileira o mesmo backup da rotina diária, como manual (um dump pode levar minutos: não roda no request). */
    public function runNow(): void
    {
        $this->authorize('admin');

        // Fila `history`: o worker dela aguenta até 900 s; na `default` (120 s) um dump longo mataria o worker e as outras filas.
        Artisan::queue('app:backup-database', ['--manual' => true])->onQueue('history');
        $this->result = 'Backup iniciado. Ele aparece na lista quando terminar; recarregue em instantes.';
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

        return view('livewire.settings.backups', [
            'files' => $files,
            'unreadable' => $unreadable,
            'bytes' => array_sum(array_map(fn (BackupFile $file): int => $file->size, $files)),
            'lastSuccess' => BackupStatus::lastSuccessAt()?->setTimezone($timezone),
            'lastError' => BackupStatus::lastError(),
            'keep' => config('backup.keep'),
            'hour' => config()->string('backup.hour'),
        ]);
    }
}
