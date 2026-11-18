<section class="mx-auto w-full max-w-[120rem] space-y-4 p-6">
    <x-page-header class="mb-6" title="Auditoria" subtitle="Eventos do sistema: acesso, usuários, backup e restauração e configurações. Nunca se edita; os registros ficam por 365 dias.">
        <x-slot:tabs><x-audit-tabs active="sistema" /></x-slot:tabs>
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
        <flux:select wire:model.live="group" label="Grupo">
            <flux:select.option value="">Todos</flux:select.option>
            @foreach ($groups as $name)
                <flux:select.option :value="$name">{{ $name }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div data-panel class="overflow-hidden rounded-xl border border-line bg-surface">
        <div class="overflow-x-auto">
            <flux:table class="w-full min-w-[48rem]">
                <caption class="sr-only">Eventos do sistema</caption>
                <flux:table.columns class="bg-canvas">
                    <flux:table.column class="w-32 px-4! border-line!"><span class="text-xs font-semibold text-ink-3">Data e hora</span></flux:table.column>
                    <flux:table.column class="w-56 px-4! border-line!"><span class="text-xs font-semibold text-ink-3">Ação</span></flux:table.column>
                    <flux:table.column class="px-4! border-line!"><span class="text-xs font-semibold text-ink-3">O que aconteceu</span></flux:table.column>
                    <flux:table.column class="w-36 px-4! border-line!"><span class="text-xs font-semibold text-ink-3">IP</span></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($logs as $log)
                        <flux:table.row wire:key="log-{{ $log->id }}">
                            <flux:table.cell class="px-4! py-[11px] border-line!"><span class="font-mono text-sm whitespace-nowrap text-ink-2">{{ $log->created_at->timezone(config()->string('app.display_timezone'))->format('d/m H:i') }}</span></flux:table.cell>
                            <flux:table.cell class="px-4! py-[11px] border-line!"><x-status-badge tone="neutral">{{ \App\Enums\AuditAction::tryFrom($log->action)?->label() ?? $log->action }}</x-status-badge></flux:table.cell>
                            <flux:table.cell class="px-4! py-[11px] whitespace-normal border-line!"><span class="text-sm break-words text-ink-2">{{ \App\Support\AuditLogText::sentence($log) }}</span></flux:table.cell>
                            <flux:table.cell class="px-4! py-[11px] border-line!"><span class="font-mono text-sm text-ink-2">{{ $log->ip ?? '—' }}</span></flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="4" class="whitespace-normal border-line!">
                                <x-empty-state icon="clipboard-document-list" title="Nenhum evento no período." description="Ajuste os filtros para ampliar a busca." />
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
        </div>

        @if ($logs->hasPages())
            <div class="border-t border-line px-4 py-3">{{ $logs->links() }}</div>
        @endif
    </div>
</section>
