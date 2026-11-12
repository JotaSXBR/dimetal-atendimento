<x-settings-page route="configuracoes.backups" title="Backup do banco" subtitle="Cópia diária do banco (conversas, tickets, contatos e configuração), guardada no RustFS. Os anexos têm cópia à parte." width="3xl">
<div class="mt-6 space-y-6">
    <dl class="divide-y divide-line rounded-[14px] border border-line bg-surface text-sm">
        <div class="flex justify-between gap-4 px-4 py-3">
            <dt class="text-ink-2">Último backup bom</dt>
            <dd class="font-mono text-ink">{{ $lastSuccess?->format('d/m/Y H:i') ?? 'Nunca' }}</dd>
        </div>
        <div class="flex justify-between gap-4 px-4 py-3">
            <dt class="text-ink-2">Backup automático</dt>
            <dd class="font-mono text-ink">todo dia às {{ $hour }}</dd>
        </div>
        <div class="flex justify-between gap-4 px-4 py-3">
            <dt class="text-ink-2">Guardados</dt>
            <dd class="font-mono text-ink">{{ $keep['diario'] }} diários, {{ $keep['semanal'] }} semanais e {{ $keep['manual'] }} manuais</dd>
        </div>
        <div class="flex justify-between gap-4 px-4 py-3">
            <dt class="text-ink-2">Espaço usado</dt>
            <dd class="font-mono text-ink">{{ number_format($bytes / 1_000_000, 1, ',', '.') }} MB em {{ count($files) }} {{ count($files) === 1 ? 'arquivo' : 'arquivos' }}</dd>
        </div>
    </dl>

    @if ($lastError !== null)
        <div role="alert" class="rounded-[14px] border border-line bg-surface p-4">
            <x-status-badge tone="danger">Última tentativa falhou</x-status-badge>
            <p class="mt-2 text-sm break-words text-ink-2">{{ $lastError }}</p>
        </div>
    @endif

    <div class="rounded-[14px] border border-line bg-surface p-4">
        <p class="text-sm font-semibold text-ink">Fazer um backup agora</p>
        <p class="mt-1 text-xs text-ink-3">Guarda os {{ $keep['manual'] }} últimos manuais, à parte dos diários e semanais.</p>
        <flux:button class="mt-3" variant="primary" wire:click="runNow">Fazer backup agora</flux:button>
        @if ($result !== '')
            <p class="mt-3 text-sm text-ink-2" role="status">{{ $result }}</p>
        @endif
    </div>

    <div class="rounded-[14px] border border-line bg-surface">
        @forelse ($files as $file)
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3 last:border-b-0">
                <div class="min-w-0">
                    <p class="font-mono text-sm break-all text-ink">{{ $file->name }}</p>
                    <p class="mt-0.5 text-xs text-ink-3">
                        {{ $file->kind->label() }} · {{ $file->createdAt->format('d/m/Y H:i') }} · {{ number_format($file->size / 1_000_000, 1, ',', '.') }} MB
                    </p>
                </div>
                <flux:button size="sm" icon="arrow-down-tray" href="{{ route('configuracoes.backups.baixar', $file->name) }}" download>Baixar</flux:button>
            </div>
        @empty
            <x-empty-state icon="circle-stack" title="Nenhum backup ainda." description="O primeiro sai no horário automático, ou use Fazer backup agora." />
        @endforelse
    </div>
</div>
</x-settings-page>
