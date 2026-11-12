<?php

namespace App\Livewire\Settings;

use App\Services\Backup\BackupCatalog;
use App\Settings\BackupStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Title;
use Livewire\Component;

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

        Artisan::queue('app:backup-database', ['--manual' => true]);
        $this->result = 'Backup iniciado. Ele aparece na lista quando terminar; recarregue em instantes.';
    }

    public function render(BackupCatalog $catalog): View
    {
        $timezone = config()->string('app.display_timezone');

        return view('livewire.settings.backups', [
            'files' => $catalog->all(),
            'bytes' => $catalog->totalBytes(),
            'lastSuccess' => BackupStatus::lastSuccessAt()?->setTimezone($timezone),
            'lastError' => BackupStatus::lastError(),
            'keep' => config('backup.keep'),
            'hour' => config()->string('backup.hour'),
        ]);
    }
}
