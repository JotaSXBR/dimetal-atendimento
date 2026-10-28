<?php

namespace App\Livewire\Settings;

use App\Models\Channel;
use App\Models\WahaEvent;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Canais')]
class Channels extends Component
{
    public string $sessionName = '';

    public function addChannel(): void
    {
        $this->validate([
            'sessionName' => ['required', 'regex:/^[A-Za-z0-9_-]{1,60}$/', 'unique:channels,waha_session'],
        ], [
            'sessionName.regex' => 'Use só letras, números, hífen e sublinhado.',
            'sessionName.unique' => 'Já existe um canal com essa sessão.',
        ]);

        Channel::create(['name' => $this->sessionName, 'waha_session' => $this->sessionName]);

        $this->reset('sessionName');
    }

    public function render(): View
    {
        return view('livewire.settings.channels', [
            'channels' => Channel::query()->orderBy('id')->get(),
            'lastEvents' => WahaEvent::query()
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (WahaEvent $event): array => [
                    'event' => $event->event,
                    'session' => $event->session,
                    'at' => $event->received_at->format('d/m/Y H:i:s'),
                ])
                ->all(),
        ]);
    }
}
