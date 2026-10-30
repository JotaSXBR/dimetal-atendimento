@props(['ticket', 'tz', 'readonly' => false, 'events' => null, 'timeline' => null, 'closer' => null])

{{-- Painel de detalhes: o estado (`details`, `sheet`) vive no x-data da tela que o contém. Em tela pequena abre por cima da conversa. --}}
<aside
    id="ticket-details"
    aria-label="Detalhes do ticket"
    class="overflow-y-auto border-s border-line bg-surface p-4"
    x-bind:class="sheet ? 'block fixed inset-0 z-40' : (details ? 'hidden lg:block' : 'hidden')"
>
    <flux:button size="sm" variant="subtle" icon="arrow-left" class="mb-3 lg:hidden" x-on:click="sheet = false">Voltar à conversa</flux:button>

    <h2 class="font-mono text-[10px] font-semibold tracking-wider text-ink-3 uppercase">Ticket</h2>
    <dl class="mt-2 space-y-2 text-sm">
        @foreach (array_filter([
            'Canal' => $ticket->channel->name,
            'Setor' => $ticket->sector?->name ?? 'Fila geral',
            'Responsável' => $ticket->assignee?->name ?? 'Não atribuído',
            'Aberto em' => $ticket->opened_at->timezone($tz)->format('d/m/Y H:i'),
            'Encerrado em' => $ticket->closed_at?->timezone($tz)->format('d/m/Y H:i'),
            'Motivo' => $readonly ? ($ticket->close_outcome?->label() ?? $ticket->closed_reason?->label()) : null,
        ], fn ($value) => $value !== null) as $label => $value)
            <div>
                <dt class="text-xs text-ink-3">{{ $label }}</dt>
                <dd class="text-ink">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    @if ($readonly && $ticket->close_summary)
        <h2 class="mt-4 font-mono text-[10px] font-semibold tracking-wider text-ink-3 uppercase">Resumo do atendimento</h2>
        <p class="mt-1 text-sm break-words whitespace-pre-wrap text-ink">{{ $ticket->close_summary }}</p>
    @endif

    <hr class="my-4 border-line">

    <livewire:contacts.contact-card :contact-id="$ticket->contact_id" :compact="true" :readonly="$readonly" :key="'contact-'.$ticket->contact_id" />

    @if ($events !== null && $timeline !== null)
        <hr class="my-4 border-line">

        <h2 class="font-mono text-[10px] font-semibold tracking-wider text-ink-3 uppercase">Linha do tempo</h2>
        <ol class="mt-2 space-y-2 text-xs">
            @forelse ($events as $event)
                <li wire:key="event-{{ $event->id }}">
                    <span class="font-mono text-[10px] text-ink-3">{{ $event->created_at->timezone($tz)->format('d/m H:i') }}</span>
                    <span class="text-ink">{{ $timeline->sentence($event) }}</span>
                </li>
            @empty
                <li class="text-ink-3">Nada registrado.</li>
            @endforelse
        </ol>
    @endif
</aside>
