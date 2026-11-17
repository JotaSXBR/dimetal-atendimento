<?php

namespace App\Livewire\Settings;

use App\Actions\Channels\CreateChannel;
use App\Models\Channel;
use App\Models\WahaEvent;
use App\Settings\WahaSettings;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Canais')]
class Channels extends Component
{
    public string $sessionName = '';

    public function addChannel(): void
    {
        app(CreateChannel::class)($this->sessionName, auth()->user());

        $this->reset('sessionName');
    }

    /** Um cartão apagou o canal: só pede nova leitura da lista. */
    #[On('channel-deleted')]
    public function refreshList(): void {}

    public function render(): View
    {
        return view('livewire.settings.channels', [
            'channels' => Channel::query()->orderBy('id')->get(),
            'webhookUrl' => WahaSettings::webhookUrl(),
            // "Recebendo" = chegou algum evento do WAHA nos últimos 10 minutos.
            'receiving' => WahaEvent::query()->where('received_at', '>=', now()->subMinutes(10))->exists(),
            'lastEvents' => WahaEvent::query()
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (WahaEvent $event): array => [
                    'event' => $event->event,
                    'session' => $event->session,
                    'at' => $event->received_at->timezone(config()->string('app.display_timezone'))->format('d/m/Y H:i:s'),
                ])
                ->all(),
        ]);
    }
}
