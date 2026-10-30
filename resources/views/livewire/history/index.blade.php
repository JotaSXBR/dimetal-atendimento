@php
    $tz = config('app.display_timezone');
@endphp

<div class="grid h-dvh grid-cols-1 bg-surface lg:grid-cols-[360px_minmax(0,1fr)_280px]">
    {{-- Busca e lista --}}
    <section class="flex min-h-0 flex-col border-e border-line {{ $ticket ? 'hidden lg:flex' : '' }}">
        <header class="space-y-3 border-b border-line p-4">
            <h1 class="text-base font-semibold text-ink">Histórico</h1>

            <flux:input icon="magnifying-glass" placeholder="Contato, protocolo ou texto da conversa" wire:model.live.debounce.400ms="q" clearable />

            <div class="grid grid-cols-2 gap-2">
                <flux:input type="date" size="sm" label="De" wire:model.live="from" />
                <flux:input type="date" size="sm" label="Até" wire:model.live="to" />

                <flux:select size="sm" wire:model.live="sectorId" label="Setor">
                    <flux:select.option value="">Todos</flux:select.option>
                    @foreach ($sectors as $sector)
                        <flux:select.option :value="$sector->id">{{ $sector->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select size="sm" wire:model.live="outcome" label="Motivo">
                    <flux:select.option value="">Todos</flux:select.option>
                    @foreach ($outcomes as $item)
                        <flux:select.option :value="$item->value">{{ $item->label() }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if ($agents !== null)
                    <flux:select size="sm" wire:model.live="userId" label="Atendente" class="col-span-2">
                        <flux:select.option value="">Todos</flux:select.option>
                        @foreach ($agents as $agent)
                            <flux:select.option :value="$agent->id">{{ $agent->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
            </div>
        </header>

        <div class="min-h-0 flex-1 overflow-y-auto">
            @forelse ($tickets as $item)
                <button
                    type="button"
                    wire:click="select({{ $item->id }})"
                    wire:key="history-{{ $item->id }}"
                    class="flex w-full items-start gap-3 px-4 py-3 text-start hover:bg-canvas {{ $ticketId === $item->id ? 'bg-canvas' : '' }}"
                >
                    <flux:avatar :name="$item->contact->label()" size="sm" />

                    <span class="min-w-0 flex-1">
                        <span class="flex items-baseline justify-between gap-2">
                            <span class="truncate text-sm font-semibold text-ink">{{ $item->contact->label() }}</span>
                            <span class="font-mono text-[10px] text-ink-3">{{ $item->closed_at?->timezone($tz)->format('d/m/Y') }}</span>
                        </span>
                        <span class="mt-0.5 block truncate text-xs text-ink-2">
                            {{ $item->protocol }} · {{ $item->sector?->name ?? 'Sem setor' }} · {{ $item->close_outcome?->label() ?? $item->closed_reason?->label() }}
                        </span>
                        @if ($item->assignee)
                            <span class="block truncate text-xs text-ink-3">{{ $item->assignee->name }}</span>
                        @endif
                    </span>
                </button>
            @empty
                <p class="p-6 text-center text-sm text-ink-3">Nenhum ticket encerrado com esses filtros.</p>
            @endforelse

            @if ($tickets->hasPages())
                <div class="border-t border-line p-3">{{ $tickets->links() }}</div>
            @endif
        </div>
    </section>

    {{-- Conversa --}}
    <section class="flex min-h-0 flex-col bg-canvas {{ $ticket ? '' : 'hidden lg:flex' }}">
        @if ($ticket)
            <header class="flex items-center gap-3 border-b border-line bg-surface px-4 py-3">
                <flux:button variant="subtle" size="sm" icon="arrow-left" class="lg:hidden" wire:click="$set('ticketId', null)" aria-label="Voltar para a lista" />
                <flux:avatar :name="$ticket->contact->label()" size="sm" />

                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-semibold text-ink">{{ $ticket->contact->label() }}</p>
                    <p class="truncate font-mono text-[11px] text-ink-3">{{ $ticket->protocol }} · {{ $ticket->contact->phoneLabel() }}</p>
                </div>
            </header>

            <div class="border-b border-line bg-surface-2 px-4 py-2 text-xs text-ink-2" role="status">
                Encerrado em {{ $ticket->closed_at?->timezone($tz)->format('d/m/Y \à\s H:i') }}@if ($closer) por {{ $closer }}@endif
                · duração {{ (int) $ticket->opened_at->diffInMinutes($ticket->closed_at, true) }} min
                · {{ $ticket->close_outcome?->label() ?? $ticket->closed_reason?->label() }}
            </div>

            @if ($hasMore)
                <div class="border-b border-warn/30 bg-warn/10 px-4 py-2 text-xs text-warn" role="status">
                    Mostrando as últimas {{ $limit }} mensagens desta conversa.
                </div>
            @endif

            <div class="flex min-h-0 flex-1 flex-col-reverse overflow-y-auto px-4 py-4">
                <div class="space-y-2">
                    @include('livewire.inbox.transcript', ['messages' => $messages, 'readonly' => true])
                </div>
            </div>
        @else
            <p class="m-auto p-6 text-center text-sm text-ink-3">Escolha um ticket encerrado para ver a conversa.</p>
        @endif
    </section>

    {{-- Detalhes --}}
    <aside class="hidden overflow-y-auto border-s border-line p-4 lg:block">
        @if ($ticket)
            <p class="font-mono text-[10px] font-semibold tracking-wider text-ink-3 uppercase">Ticket encerrado</p>

            <dl class="mt-3 space-y-3 text-sm">
                @foreach ([
                    'Protocolo' => $ticket->protocol,
                    'Canal' => $ticket->channel->name,
                    'Setor' => $ticket->sector?->name ?? 'Sem setor',
                    'Responsável' => $ticket->assignee?->name ?? '—',
                    'Aberto em' => $ticket->opened_at->timezone($tz)->format('d/m/Y H:i'),
                    'Encerrado em' => $ticket->closed_at?->timezone($tz)->format('d/m/Y H:i'),
                    'Motivo' => $ticket->close_outcome?->label() ?? $ticket->closed_reason?->label(),
                ] as $label => $value)
                    <div>
                        <dt class="text-xs text-ink-3">{{ $label }}</dt>
                        <dd class="text-ink">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>

            @if ($ticket->close_summary)
                <p class="mt-5 font-mono text-[10px] font-semibold tracking-wider text-ink-3 uppercase">Resumo do atendimento</p>
                <p class="mt-1 text-sm break-words whitespace-pre-wrap text-ink">{{ $ticket->close_summary }}</p>
            @endif

            <p class="mt-5 font-mono text-[10px] font-semibold tracking-wider text-ink-3 uppercase">Linha do tempo</p>
            <ol class="mt-2 space-y-2 text-xs">
                @foreach ($events as $event)
                    <li wire:key="event-{{ $event->id }}">
                        <span class="font-mono text-[10px] text-ink-3">{{ $event->created_at->timezone($tz)->format('d/m H:i') }}</span>
                        <span class="text-ink">{{ \App\Support\TicketEventText::label($event->type) }}</span>
                        @if ($event->actor)
                            <span class="text-ink-3">· {{ $event->actor->name }}</span>
                        @endif
                        @if (($detail = \App\Support\TicketEventText::detail($event)) !== '')
                            <p class="break-words text-ink-2">{{ $detail }}</p>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    </aside>
</div>
