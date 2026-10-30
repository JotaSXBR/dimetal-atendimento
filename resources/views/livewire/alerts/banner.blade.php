@php($edge = $inset ? '-mx-6 -mt-6 mb-6 lg:-mx-8 lg:-mt-8' : '')

<div>
    <div role="alert" aria-live="assertive">
        @if ($down->isNotEmpty())
            <div class="{{ $edge }} flex flex-wrap items-center gap-x-3 gap-y-2 bg-danger px-5 py-2.5 text-sm text-white" data-alert="connection-down">
                <flux:icon.exclamation-triangle variant="mini" class="size-4 shrink-0" />
                <p class="min-w-0 flex-1"><strong>WhatsApp desconectado</strong>
                    · {{ $down->pluck('name')->join(', ') }} {{ $down->count() === 1 ? 'não está' : 'não estão' }} recebendo nem enviando mensagens. A faixa some quando a conexão voltar.</p>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" wire:click="mute(15)" class="rounded-lg border border-white/60 px-3 py-1.5 text-xs font-semibold hover:bg-white/10">Silenciar som 15 min</button>
                    <button type="button" wire:click="mute(60)" class="rounded-lg border border-white/60 px-3 py-1.5 text-xs font-semibold hover:bg-white/10">Silenciar 1 h</button>
                    @if ($canSeeChannels)
                        <a href="{{ route('configuracoes.canais') }}" wire:navigate class="rounded-lg border border-white px-3 py-1.5 text-xs font-semibold hover:bg-white/10">Ver canais</a>
                    @endif
                </div>
            </div>
        @endif
    </div>

    <div role="status" aria-live="polite">
        @if ($recovered !== '')
            <div class="{{ $edge }} flex items-center gap-3 bg-accent/10 px-5 py-2.5 text-sm text-accent-deep" data-alert="connection-up"
                x-data x-init="setTimeout(() => $wire.dismissRecovered(), 10000)">
                <flux:icon.check-circle variant="mini" class="size-4 shrink-0" />
                <p class="min-w-0 flex-1"><strong>WhatsApp voltou</strong> · {{ $recovered }} está conectado de novo. As mensagens em espera já estão saindo.</p>
                <span class="text-xs">some em 10 s</span>
            </div>
        @endif

        @if ($breached > 0)
            <div class="{{ $edge }} flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-line bg-danger/10 px-5 py-2.5 text-sm text-danger" data-alert="sla-breached">
                <flux:icon.clock variant="mini" class="size-4 shrink-0" />
                <p class="min-w-0 flex-1"><strong>SLA estourado</strong>
                    · {{ $breached }} {{ $breached === 1 ? 'ticket espera' : 'tickets esperam' }} além do prazo sem resposta.</p>
                <a href="{{ route('inbox') }}" wire:navigate class="rounded-lg bg-danger px-3 py-1.5 text-xs font-semibold text-white hover:opacity-90">Abrir atendimento</a>
            </div>
        @endif
    </div>
</div>
