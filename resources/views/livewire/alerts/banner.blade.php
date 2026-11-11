@php
    $edge = $inset ? '-mx-6 -mt-6 mb-6 lg:-mx-8 lg:-mt-8' : '';
    // Uma linha só (spec 4a): o texto trunca e o texto completo fica no `title`; o DOM e o aria-live mantêm o texto inteiro.
    $downRest = $down->isEmpty() ? '' : $down->pluck('name')->join(', ').' '.($down->count() === 1 ? 'não está' : 'não estão').' recebendo nem enviando mensagens. A faixa some quando a conexão voltar.';
    $recoveredRest = $recovered === '' ? '' : $recovered.' está conectado de novo. As mensagens em espera já estão saindo.';
    $breachedRest = $breached > 0 ? $breached.' '.($breached === 1 ? 'ticket espera' : 'tickets esperam').' além do prazo sem resposta.' : '';
@endphp

<div>
    <div role="alert" aria-live="assertive">
        @if ($down->isNotEmpty())
            <div class="{{ $edge }} flex items-center gap-x-3 max-sm:flex-wrap max-sm:gap-y-1 bg-danger px-5 py-1.5 text-xs text-danger-foreground" data-alert="connection-down">
                <flux:icon.exclamation-triangle variant="mini" class="size-4 shrink-0" />
                <p class="min-w-0 flex-1 sm:truncate" title="WhatsApp desconectado · {{ $downRest }}"><strong>WhatsApp desconectado</strong> · {{ $downRest }}</p>
                <div class="flex shrink-0 items-center gap-2 max-sm:w-full max-sm:flex-wrap">
                    <flux:button size="xs" variant="ghost" wire:click="mute(15)" class="border border-white/60 text-danger-foreground! hover:bg-white/10!">Silenciar som 15 min</flux:button>
                    <flux:button size="xs" variant="ghost" wire:click="mute(60)" class="border border-white/60 text-danger-foreground! hover:bg-white/10!">Silenciar 1 h</flux:button>
                    @if ($canSeeChannels)
                        <flux:button size="xs" variant="ghost" :href="route('configuracoes.canais')" wire:navigate class="border border-white text-danger-foreground! hover:bg-white/10!">Ver canais</flux:button>
                    @endif
                </div>
            </div>
        @endif
    </div>

    <div role="status" aria-live="polite">
        @if ($recovered !== '')
            <div class="{{ $edge }} flex items-center gap-3 max-sm:flex-wrap bg-ok/10 px-5 py-1.5 text-xs text-ok" data-alert="connection-up"
                x-data x-init="setTimeout(() => $wire.dismissRecovered(), 10000)">
                <flux:icon.check-circle variant="mini" class="size-4 shrink-0" />
                <p class="min-w-0 flex-1 sm:truncate" title="WhatsApp voltou · {{ $recoveredRest }}"><strong>WhatsApp voltou</strong> · {{ $recoveredRest }}</p>
                <span class="text-xs">some em 10 s</span>
            </div>
        @endif

        @if ($breached > 0)
            <div class="{{ $edge }} flex items-center gap-x-3 max-sm:flex-wrap max-sm:gap-y-1 border-b border-line bg-danger/10 px-5 py-1.5 text-xs text-danger" data-alert="sla-breached">
                <flux:icon.clock variant="mini" class="size-4 shrink-0" />
                <p class="min-w-0 flex-1 sm:truncate" title="SLA estourado · {{ $breachedRest }}"><strong>SLA estourado</strong> · {{ $breachedRest }}</p>
                <flux:button size="xs" variant="danger" :href="route('inbox')" wire:navigate class="shrink-0">Abrir atendimento</flux:button>
            </div>
        @endif
    </div>
</div>
