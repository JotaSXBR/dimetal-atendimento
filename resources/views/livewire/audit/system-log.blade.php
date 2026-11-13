<section class="mx-auto w-full max-w-6xl space-y-4 p-6">
    <x-page-header title="Auditoria" subtitle="Eventos do sistema: acesso, usuários, backup e restauração e configurações. Nunca se edita; os registros ficam por 365 dias." />

    <x-audit-tabs active="sistema" />

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

    <div class="overflow-x-auto rounded-[14px] border border-line bg-surface">
        <table class="w-full text-sm">
            <thead class="text-xs text-ink-3">
                <tr>
                    <th scope="col" class="px-4 py-2 text-start font-normal">Data e hora</th>
                    <th scope="col" class="px-4 py-2 text-start font-normal">Ação</th>
                    <th scope="col" class="px-4 py-2 text-start font-normal">O que aconteceu</th>
                    <th scope="col" class="px-4 py-2 text-start font-normal">IP</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-line">
                @forelse ($logs as $log)
                    <tr wire:key="log-{{ $log->id }}">
                        <td class="px-4 py-2 font-mono text-xs whitespace-nowrap text-ink-2">{{ $log->created_at->timezone(config()->string('app.display_timezone'))->format('d/m H:i') }}</td>
                        <td class="px-4 py-2 text-ink">{{ \App\Enums\AuditAction::tryFrom($log->action)?->label() ?? $log->action }}</td>
                        <td class="px-4 py-2 break-words text-ink-2">{{ \App\Support\AuditLogText::sentence($log) }}</td>
                        <td class="px-4 py-2 font-mono text-xs text-ink-2">{{ $log->ip ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4"><x-empty-state compact icon="clipboard-document-list" title="Nenhum evento no período." description="Ajuste os filtros para ampliar a busca." /></td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div>{{ $logs->links() }}</div>
</section>
