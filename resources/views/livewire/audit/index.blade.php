<section class="mx-auto w-full max-w-6xl space-y-4 p-6">
    <x-page-header title="Auditoria" subtitle="Trilha dos eventos de ticket: assumir, transferir, encerrar e mais. Nunca se edita nem se apaga." />

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

    <div class="overflow-x-auto rounded-[14px] border border-line bg-surface">
        <table class="w-full text-sm">
            <thead class="text-xs text-ink-3">
                <tr>
                    <th scope="col" class="px-4 py-2 text-start font-normal">Data e hora</th>
                    <th scope="col" class="px-4 py-2 text-start font-normal">Usuário</th>
                    <th scope="col" class="px-4 py-2 text-start font-normal">Ação</th>
                    <th scope="col" class="px-4 py-2 text-start font-normal">Ticket</th>
                    <th scope="col" class="px-4 py-2 text-start font-normal">Detalhe</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($events as $event)
                    <tr wire:key="event-{{ $event->id }}">
                        <td class="px-4 py-2 font-mono text-xs whitespace-nowrap text-ink-2">{{ $event->created_at->timezone(config()->string('app.display_timezone'))->format('d/m H:i') }}</td>
                        <td class="px-4 py-2 text-ink">{{ $event->actor?->name ?? 'Sistema' }}</td>
                        <td class="px-4 py-2 text-ink">{{ \App\Livewire\Audit\Index::typeLabel($event->type) }}</td>
                        <td class="px-4 py-2 font-mono text-xs text-ink-2">
                            @if ($event->ticket?->protocol)
                                {{-- O Histórico só abre ticket encerrado; o aberto está na Fila. --}}
                                <a href="{{ $event->ticket->status === \App\Enums\TicketStatus::ENCERRADO ? route('historico', ['ticketId' => $event->ticket->id]) : route('inbox') }}" wire:navigate class="underline-offset-2 hover:underline">{{ $event->ticket->protocol }}</a>
                            @else
                                —
                            @endif
                        </td>
                        <td class="px-4 py-2 break-words text-ink-2">{{ $timeline->detail($event) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-ink-3">Nenhum evento no período.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $events->links() }}</div>
</section>
