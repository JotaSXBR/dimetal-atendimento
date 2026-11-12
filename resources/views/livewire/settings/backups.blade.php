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
        @if ($lastRestore !== null)
            <div class="flex justify-between gap-4 px-4 py-3">
                <dt class="text-ink-2">Última restauração</dt>
                <dd class="text-right font-mono text-ink">{{ $lastRestore['at']->format('d/m/Y H:i') }} · {{ $lastRestore['file'] }} · {{ $lastRestore['actor'] }}</dd>
            </div>
        @endif
    </dl>

    @if ($lastError !== null)
        <div role="alert" class="rounded-[14px] border border-line bg-surface p-4">
            <x-status-badge tone="danger">Última tentativa falhou</x-status-badge>
            <p class="mt-2 text-sm break-words text-ink-2">{{ $lastError }}</p>
        </div>
    @endif

    @if ($restoring)
        <div role="status" class="rounded-[14px] border border-line bg-surface p-4">
            <x-status-badge tone="warn">Restauração em andamento</x-status-badge>
            <p class="mt-2 text-sm text-ink-2"><a href="{{ route('restauracao') }}" class="font-medium text-accent-content underline">Acompanhar o andamento</a></p>
        </div>
    @endif

    <div class="rounded-[14px] border border-line bg-surface p-4">
        <p class="text-sm font-semibold text-ink">Fazer um backup agora</p>
        <p class="mt-1 text-xs text-ink-3">Guarda os {{ $keep['manual'] }} últimos manuais, à parte dos diários e semanais.</p>
        <flux:button class="mt-3" variant="primary" wire:click="runNow" wire:loading.attr="disabled" wire:target="runNow">Fazer backup agora</flux:button>
        @if ($result !== '')
            <p class="mt-3 text-sm text-ink-2" role="status">{{ $result }}</p>
        @endif
    </div>

    @if ($unreadable)
        <div role="alert" class="rounded-[14px] border border-line bg-surface p-4">
            <x-status-badge tone="warn">Bucket ilegível</x-status-badge>
            <p class="mt-2 text-sm text-ink-2">Não foi possível ler o bucket de backups. Confira `BACKUP_BUCKET` e as chaves do RustFS; o motivo está no log do app.</p>
        </div>
    @endif

    @if ($problem !== null)
        <div role="alert" data-restore-problem class="rounded-[14px] border border-line bg-surface p-4">
            <x-status-badge tone="danger">Não foi possível seguir</x-status-badge>
            <p class="mt-2 text-sm break-words text-ink-2">{{ $problem }}</p>
        </div>
    @endif

    @if ($source !== null || $target !== null)
        <section aria-labelledby="restore-check" class="rounded-[14px] border border-line bg-surface p-4">
            <h2 id="restore-check" class="text-sm font-semibold text-ink">{{ $target !== null ? 'Voltar para o banco guardado' : 'Conferência antes de restaurar' }}</h2>

            <dl class="mt-3 divide-y divide-line text-sm">
                <div class="flex justify-between gap-4 py-2"><dt class="text-ink-2">{{ $target !== null ? 'Banco' : 'Arquivo' }}</dt><dd class="font-mono break-all text-ink">{{ $info['name'] ?? '' }}</dd></div>
                <div class="flex justify-between gap-4 py-2"><dt class="text-ink-2">{{ $target !== null ? 'Guardado em' : 'Criado em' }}</dt><dd class="font-mono text-ink">{{ $info['created'] ?? '' }}</dd></div>
                @if ($source !== null)
                    <div class="flex justify-between gap-4 py-2"><dt class="text-ink-2">Banco de origem</dt><dd class="font-mono text-ink">{{ $info['database'] ?? '' }}</dd></div>
                    <div class="flex justify-between gap-4 py-2"><dt class="text-ink-2">Versões (servidor / pg_dump)</dt><dd class="font-mono text-ink">{{ $info['server'] ?? '' }} / {{ $info['dump'] ?? '' }}</dd></div>
                    <div class="flex justify-between gap-4 py-2"><dt class="text-ink-2">Compressão</dt><dd class="font-mono text-ink">{{ $info['compression'] ?? '' }}</dd></div>
                @endif
                <div class="flex justify-between gap-4 py-2"><dt class="text-ink-2">Tamanho</dt><dd class="font-mono text-ink">{{ $info['size'] ?? '' }}</dd></div>
            </dl>

            <div class="mt-4 rounded-lg bg-canvas p-3 text-xs text-ink-2">
                <p class="font-medium text-ink">O que vai acontecer</p>
                <ol class="mt-1 list-decimal space-y-0.5 pl-4">
                    @if ($source !== null)
                        <li>Um backup de segurança do banco atual.</li>
                        <li>O arquivo é restaurado num banco novo, com o sistema no ar, e conferido.</li>
                    @endif
                    <li>Alguns segundos em manutenção: o banco atual vira um banco guardado e o novo assume o lugar.</li>
                    <li>O esquema é atualizado e as filas voltam. As mensagens do WhatsApp posteriores ao backup são recuperadas (até 72 h).</li>
                    <li>O banco anterior fica guardado por {{ config('backup.restore.keep_old_days') }} dias; dá para voltar a ele por aqui.</li>
                </ol>
                <p class="mt-2">Quem estiver usando o sistema vê a página de manutenção. Se o seu usuário não existir no banco restaurado, você precisará entrar de novo.</p>
            </div>

            <div class="mt-4 max-w-xs">
                <flux:input wire:model.live.debounce.250ms="confirm" label="Digite {{ $confirmWord }} para confirmar" autocomplete="off" />
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                <flux:button variant="danger" wire:click="{{ $target !== null ? 'switchBack' : 'restore' }}" wire:loading.attr="disabled" wire:target="restore,switchBack" :disabled="$confirm !== $confirmWord">
                    {{ $target !== null ? 'Voltar para este banco' : 'Restaurar este backup' }}
                </flux:button>
                <flux:button wire:click="cancelRestore">Cancelar</flux:button>
            </div>
        </section>
    @endif

    <div class="rounded-[14px] border border-line bg-surface">
        @forelse ($files as $file)
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3 last:border-b-0">
                <div class="min-w-0">
                    <p class="font-mono text-sm break-all text-ink">{{ $file->name }}</p>
                    <p class="mt-0.5 text-xs text-ink-3">
                        {{ $file->kind->label() }} · {{ $file->createdAt->format('d/m/Y H:i') }} · {{ number_format($file->size / 1_000_000, 1, ',', '.') }} MB
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <flux:button size="sm" wire:click="chooseBackup('{{ $file->name }}')" wire:loading.attr="disabled" wire:target="chooseBackup">Restaurar</flux:button>
                    <flux:button size="sm" icon="arrow-down-tray" href="{{ route('configuracoes.backups.baixar', $file->name) }}" download>Baixar</flux:button>
                </div>
            </div>
        @empty
            @unless ($unreadable)
                <x-empty-state icon="circle-stack" title="Nenhum backup ainda." description="O primeiro sai no horário automático, ou use Fazer backup agora." />
            @endunless
        @endforelse
    </div>

    <div class="rounded-[14px] border border-line bg-surface p-4">
        <p class="text-sm font-semibold text-ink">Restaurar de um arquivo do seu computador</p>
        <p class="mt-1 text-xs text-ink-3">Um backup deste sistema (`.dump`, até {{ $uploadMaxMb }} MB), por exemplo vindo da cópia da empresa. O arquivo é conferido antes de qualquer coisa.</p>
        <input type="file" wire:model="upload" accept=".dump" aria-label="Arquivo de backup (.dump)" class="mt-3 block w-full text-sm text-ink-2 file:mr-3 file:rounded-lg file:border-0 file:bg-canvas file:px-3 file:py-2 file:text-sm file:font-medium file:text-ink">
        <p wire:loading wire:target="upload" class="mt-2 text-xs text-ink-3" role="status">Enviando e conferindo o arquivo…</p>
        @error('upload')
            <p class="mt-2 text-xs text-danger" role="alert">{{ $message }}</p>
        @enderror
    </div>

    @if ($stored !== [])
        <div class="rounded-[14px] border border-line bg-surface">
            <p class="border-b border-line px-4 py-3 text-sm font-semibold text-ink">Bancos guardados</p>
            @foreach ($stored as $database)
                <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3 last:border-b-0">
                    <div class="min-w-0">
                        <p class="font-mono text-sm break-all text-ink">{{ $database->name }}</p>
                        <p class="mt-0.5 text-xs text-ink-3">{{ $database->createdAt->format('d/m/Y H:i') }} · {{ number_format($database->bytes / 1_000_000, 1, ',', '.') }} MB</p>
                    </div>
                    <flux:button size="sm" wire:click="chooseStored('{{ $database->name }}')" wire:loading.attr="disabled" wire:target="chooseStored">Voltar para este</flux:button>
                </div>
            @endforeach
        </div>
    @endif
</div>
</x-settings-page>
