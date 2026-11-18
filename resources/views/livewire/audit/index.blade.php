<section class="mx-auto w-full max-w-[120rem] space-y-4 p-6">
    <x-page-header class="mb-6" title="Auditoria" subtitle="Trilha dos eventos de ticket: assumir, transferir, encerrar e mais. Nunca se edita nem se apaga.">
        <x-slot:tabs><x-audit-tabs active="tickets" /></x-slot:tabs>
    </x-page-header>

    <div class="grid gap-3 sm:grid-cols-3">
        <flux:select wire:model.live="period" label="Período">
            @foreach (\App\Livewire\Audit\Index::PERIODS as $value => $label)
                <flux:select.option :value="$value">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="userId" label="Usuário">
            <flux:select.option value="">Todos</flux:select.option>
            @foreach ($users as $user)
                <flux:select.option :value="$user->id">{{ $user->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="type" label="Ação">
            <flux:select.option value="">Todas</flux:select.option>
            @foreach ($types as $value => $label)
                <flux:select.option :value="$value">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div data-panel class="overflow-hidden rounded-xl border border-line bg-surface">
        <div class="overflow-x-auto">
            <flux:table class="w-full min-w-[56rem]">
                <caption class="sr-only">Eventos de tickets</caption>
                <flux:table.columns class="bg-canvas">
                    <flux:table.column class="w-32 px-4! border-line!"><span class="text-xs font-semibold text-ink-3">Data e hora</span></flux:table.column>
                    <flux:table.column class="w-44 px-4! border-line!"><span class="text-xs font-semibold text-ink-3">Usuário</span></flux:table.column>
                    <flux:table.column class="w-44 px-4! border-line!"><span class="text-xs font-semibold text-ink-3">Ação</span></flux:table.column>
                    <flux:table.column class="w-32 px-4! border-line!"><span class="text-xs font-semibold text-ink-3">Ticket</span></flux:table.column>
                    <flux:table.column class="px-4! border-line!"><span class="text-xs font-semibold text-ink-3">Detalhe</span></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($events as $event)
                        <flux:table.row wire:key="event-{{ $event->id }}">
                            <flux:table.cell class="px-4! py-[11px] border-line!"><span class="font-mono text-sm whitespace-nowrap text-ink-2">{{ $event->created_at->timezone(config()->string('app.display_timezone'))->format('d/m H:i') }}</span></flux:table.cell>
                            <flux:table.cell class="px-4! py-[11px] border-line!"><span class="block truncate text-sm text-ink" title="{{ $event->actor?->name ?? 'Sistema' }}">{{ $event->actor?->name ?? 'Sistema' }}</span></flux:table.cell>
                            <flux:table.cell class="px-4! py-[11px] border-line!"><x-status-badge tone="neutral">{{ \App\Livewire\Audit\Index::typeLabel($event->type) }}</x-status-badge></flux:table.cell>
                            <flux:table.cell class="px-4! py-[11px] border-line!">
                                @if ($event->ticket?->protocol)
                                    {{-- O Histórico só abre ticket encerrado; o aberto está na Fila. --}}
                                    <a href="{{ $event->ticket->status === \App\Enums\TicketStatus::ENCERRADO ? route('historico', ['ticketId' => $event->ticket->id]) : route('inbox') }}" wire:navigate class="inline-flex min-h-6 items-center font-mono text-sm text-ink-2 underline-offset-2 hover:underline">{{ $event->ticket->protocol }}</a>
                                @else
                                    <span class="font-mono text-sm text-ink-2">—</span>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell class="px-4! py-[11px] whitespace-normal border-line!"><span class="text-sm break-words text-ink-2">{{ $timeline->detail($event) }}</span></flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="5" class="whitespace-normal border-line!">
                                <x-empty-state icon="clipboard-document-list" title="Nenhum evento no período." description="Ajuste os filtros para ampliar a busca." />
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>

        @if ($events->hasPages())
            <div class="border-t border-line px-4 py-3">{{ $events->links() }}</div>
        @endif
    </div>
</section>
