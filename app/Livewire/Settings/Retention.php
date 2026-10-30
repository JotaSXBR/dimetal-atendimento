<?php

namespace App\Livewire\Settings;

use App\Console\Commands\PurgeMediaCommand;
use App\Models\Message;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Retenção')]
class Retention extends Component
{
    public string $result = '';

    public function mount(): void
    {
        $this->authorize('admin');
    }

    /** Roda agora o mesmo expurgo da rotina das 03:30; o texto e os eventos ficam, só o arquivo vai. */
    public function purgeNow(): void
    {
        $this->authorize('admin');

        Artisan::call('app:purge-media');
        $this->result = trim(Artisan::output());
    }

    public function render(): View
    {
        $kept = Message::query()->whereNotNull('local_path');
        $expired = (clone $kept)->where('created_at', '<', now()->subDays(PurgeMediaCommand::RETENTION_DAYS));

        return view('livewire.settings.retention', [
            'days' => PurgeMediaCommand::RETENTION_DAYS,
            'files' => $kept->count(),
            'bytes' => (int) $kept->sum('media_size'),
            'expired' => $expired->count(),
        ]);
    }
}
