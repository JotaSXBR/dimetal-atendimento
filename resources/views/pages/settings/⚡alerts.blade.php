<?php

use App\Support\AlertSettings;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Alertas')] class extends Component {
    public bool $soundMessage = true;
    public bool $soundQueue = true;
    public bool $soundAssigned = true;
    public bool $soundSla = true;
    public bool $repeat = true;
    public bool $desktop = false;
    public int $volume = AlertSettings::DEFAULT_VOLUME;

    public function mount(): void
    {
        $s = Auth::user()->alertSettings();

        $this->soundMessage = $s->soundMessage;
        $this->soundQueue = $s->soundQueue;
        $this->soundAssigned = $s->soundAssigned;
        $this->soundSla = $s->soundSla;
        $this->repeat = $s->repeat;
        $this->desktop = $s->desktopNotifications;
        $this->volume = $s->volume;
    }

    #[Computed]
    public function mutedUntil(): ?CarbonImmutable
    {
        $s = Auth::user()->alertSettings();

        return $s->criticalMuted() ? $s->muteCriticalUntil : null;
    }

    public function save(): void
    {
        $this->validate([
            'volume' => ['required', 'integer', 'between:0,100'],
            'soundMessage' => ['boolean'], 'soundQueue' => ['boolean'], 'soundAssigned' => ['boolean'],
            'soundSla' => ['boolean'], 'repeat' => ['boolean'], 'desktop' => ['boolean'],
        ]);

        $user = Auth::user();
        // O silêncio do crítico tem botão próprio; salvar as escolhas não o apaga.
        $data = AlertSettings::fromArray([
            'sound' => ['message' => $this->soundMessage, 'queue' => $this->soundQueue, 'assigned' => $this->soundAssigned, 'sla' => $this->soundSla],
            'volume' => $this->volume,
            'repeat' => $this->repeat,
            'desktop_notifications' => $this->desktop,
            'mute_critical_until' => $user->alertSettings()->muteCriticalUntil?->toIso8601String(),
        ])->toArray();

        $user->forceFill(['alert_settings' => $data])->save();
        $this->dispatch('alert-settings-updated', settings: $data);

        Flux::toast(variant: 'success', text: 'Alertas salvos.');
    }

    public function mute(int $minutes): void
    {
        $user = Auth::user();

        if ($user->muteCriticalFor($minutes)) {
            $this->dispatch('alert-settings-updated', settings: $user->alertSettings()->toArray());
            unset($this->mutedUntil);
        }
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">Alertas</flux:heading>

    <x-pages::settings.layout heading="Alertas" subheading="Som, repetição e balão do navegador. Valem só para o seu usuário.">
        <form wire:submit="save" class="space-y-6">
            <div class="space-y-3">
                <p class="font-mono text-[11px] font-semibold tracking-wider text-ink-3 uppercase">Som por tipo</p>
                <flux:switch wire:model="soundMessage" label="Mensagem em ticket meu" />
                <flux:switch wire:model="soundQueue" label="Ticket novo na minha fila" />
                <flux:switch wire:model="soundAssigned" label="Ticket atribuído ou transferido a mim" />
                <flux:switch wire:model="soundSla" label="SLA perto de estourar ou estourado" description="Desligar cala o som e o balão do SLA." />
            </div>

            <div class="space-y-3">
                <p class="font-mono text-[11px] font-semibold tracking-wider text-ink-3 uppercase">Repetição e balão</p>
                <flux:switch wire:model="repeat" label="Repetir até resolver" description="Só SLA estourado e WhatsApp caído repetem." />
                <div
                    x-data="{ async ask() { await $nextTick(); if (! $wire.desktop) return; if (! ('Notification' in window)) { $wire.desktop = false; return; } if (Notification.permission !== 'granted' && await Notification.requestPermission() !== 'granted') { $wire.desktop = false; } } }"
                    x-on:click="ask()">
                    <flux:switch wire:model="desktop" label="Balão do navegador" description="Só WhatsApp caído e SLA estourado, sem texto do cliente. O navegador pede permissão." />
                </div>
            </div>

            <div class="space-y-2">
                <label for="alert-volume" class="font-mono text-[11px] font-semibold tracking-wider text-ink-3 uppercase">Volume</label>
                <div class="flex items-center gap-3">
                    <input id="alert-volume" type="range" min="0" max="100" step="5" wire:model.live.debounce.300ms="volume" class="w-full accent-accent" />
                    <span class="w-10 text-end text-sm font-semibold">{{ $volume }}%</span>
                </div>
                <flux:error name="volume" />
            </div>

            <div class="space-y-2">
                <p class="font-mono text-[11px] font-semibold tracking-wider text-ink-3 uppercase">Silenciar o alerta crítico</p>
                <flux:text size="sm">Cala só o som do WhatsApp caído. A faixa vermelha continua até a conexão voltar.</flux:text>
                <div class="flex flex-wrap items-center gap-2">
                    <flux:button type="button" wire:click="mute(15)" icon="bell-slash" size="sm">15 minutos</flux:button>
                    <flux:button type="button" wire:click="mute(60)" icon="bell-slash" size="sm">1 hora</flux:button>
                    @if ($this->mutedUntil)
                        <span class="text-xs text-ink-3" role="status">Silenciado até {{ $this->mutedUntil->setTimezone(config('app.display_timezone'))->format('H:i') }}</span>
                    @endif
                </div>
            </div>

            <div class="space-y-2">
                <p class="font-mono text-[11px] font-semibold tracking-wider text-ink-3 uppercase">Prévia dos sons</p>
                <div class="flex flex-wrap gap-2">
                    @foreach (['comum' => 'Comum', 'sla' => 'SLA', 'critico' => 'Crítico'] as $kind => $label)
                        <flux:button type="button" icon="speaker-wave" size="sm" x-on:click="window.dispatchEvent(new CustomEvent('alerts-preview', { detail: { kind: '{{ $kind }}' } }))">{{ $label }}</flux:button>
                    @endforeach
                </div>
            </div>

            <flux:button variant="primary" type="submit" data-test="save-alerts-button">Salvar</flux:button>
        </form>
    </x-pages::settings.layout>
</section>
