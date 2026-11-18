@props(['ticket', 'tz', 'readonly' => false, 'events' => null, 'timeline' => null, 'closer' => null, 'showChannel' => false])

{{-- Painel de detalhes: o estado (`details`, `sheet`) vive no x-data da tela que o contém. Em tela pequena abre por cima da conversa.
     Coluna flex de rolagem com `p-4`: o rodapé de ações do cartão do contato (`sticky -bottom-4 -mx-4 -mb-4`) depende desse `p-4`; se mudar o padding, mude os negativos.
     Bloco Ticket nos dois (desenho do Pencil), mesmo repetindo a linha do cabeçalho da conversa; Encerrado em, Motivo e Resumo só quando existem.
     Aviso de SLA no fim do bloco, só na Inbox, com a mesma condição e o mesmo texto do cabeçalho. --}}
<aside
    id="ticket-details"
    aria-label="Detalhes do ticket"
    class="overflow-y-auto border-s border-line bg-surface p-4"
    x-bind:class="sheet ? 'flex flex-col fixed inset-0 z-40' : (details ? 'hidden 2xl:flex 2xl:flex-col' : 'hidden')"
>
    <flux:button size="sm" variant="subtle" icon="arrow-left" class="mb-3 self-start 2xl:hidden" x-on:click="sheet = false">Voltar à conversa</flux:button>

    <x-disclosure title="Ticket" testid="details-ticket">
        <dl class="space-y-2.5">
            @foreach (array_filter([
                'Canal' => $ticket->channel->name.($showChannel ? ' · '.($ticket->channel->phoneLabel() ?? 'sem número') : ''),
                'Setor' => $ticket->sector?->name ?? 'Fila geral',
                'Responsável' => $ticket->assignee?->name ?? 'Não atribuído',
                'Aberto em' => $ticket->opened_at->timezone($tz)->format('d/m/Y H:i'),
                'Encerrado em' => $ticket->closed_at?->timezone($tz)->format('d/m/Y H:i'),
                'Motivo' => $ticket->close_outcome?->label() ?? $ticket->closed_reason?->label(),
            ], fn ($value) => $value !== null) as $label => $value)
                <div class="space-y-0.5">
                    <dt class="text-xs text-ink-3">{{ $label }}</dt>
                    <dd class="text-sm font-medium break-words text-ink">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>

        @if ($ticket->close_summary)
            <h2 class="mt-4 text-xs font-semibold text-ink-3">Resumo do atendimento</h2>
            <p class="mt-1 text-sm break-words whitespace-pre-wrap text-ink">{{ $ticket->close_summary }}</p>
        @endif

        @if (! $readonly && $ticket->status !== \App\Enums\TicketStatus::ENCERRADO && $ticket->waiting_since !== null && $ticket->sla_due_at !== null)
            @php($slaState = $ticket->slaState())
            @php($breached = $slaState === 'estourou')
            @php($when = app(\App\Services\Inbox\BusinessHours::class)->whenLabel($ticket->sla_due_at, now()))
            {{-- Sem `role="status"`: o anúncio ao leitor de tela fica só no `header-sla` do cabeçalho. --}}
            <div data-testid="details-sla" @class(['mt-2.5 rounded-lg p-2.5 text-xs font-semibold', 'bg-danger/10 text-danger' => $breached, 'bg-warn-soft text-warn' => $slaState === 'perto', 'bg-muted text-ink-2' => $slaState === null])>
                {{ $breached ? 'SLA estourado: venceu '.$when : 'Responder até '.$when }}
            </div>
        @endif
    </x-disclosure>

    <livewire:contacts.contact-card :contact-id="$ticket->contact_id" :compact="true" :readonly="$readonly" :current-ticket-id="$ticket->id" :key="'contact-'.$ticket->contact_id" />

    @if ($events !== null && $timeline !== null)
        <x-disclosure title="Linha do tempo" :open="false" testid="details-timeline">
        <ol class="space-y-2 text-xs">
            @forelse ($events as $event)
                <li wire:key="event-{{ $event->id }}">
                    <span class="font-mono text-xs text-ink-3">{{ $event->created_at->timezone($tz)->format('d/m H:i') }}</span>
                    <span class="text-ink">{{ $timeline->sentence($event) }}</span>
                </li>
            @empty
                <li class="text-ink-3">Nada registrado.</li>
            @endforelse
        </ol>
        </x-disclosure>
    @endif
</aside>
