<x-settings-page route="configuracoes.google" title="Google Contatos" subtitle="Os contatos que você edita no sistema são copiados para o Google Contatos da empresa (só de ida). Contato que nunca foi editado não é enviado.">
    @if (session('google_error'))
        <flux:callout class="mt-4" variant="danger" icon="exclamation-triangle" :text="session('google_error')" />
    @endif

    <x-panel class="mt-6" title="Conta do Google" :subtitle="$connection ? 'Conectado em '.$connection->connected_at?->timezone(config('app.display_timezone'))->format('d/m/Y H:i') : null">
        @if ($connection)
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3">
                <p class="min-w-0 text-sm font-semibold break-all text-ink">{{ $connection->account_email }}</p>
                <x-status-badge :status="$connection->status" />
            </div>

            @if ($connection->last_error)
                <p class="px-4 pt-3 text-xs text-danger" role="alert">Último erro: {{ $connection->last_error }}</p>
            @endif

            <dl class="grid grid-cols-1 gap-3 p-4 text-sm sm:grid-cols-3">
                <div><dt class="text-xs text-ink-3">Enviados</dt><dd class="font-mono text-xs text-ink">{{ $synced }} sincronizado{{ $synced === 1 ? '' : 's' }}</dd></div>
                <div><dt class="text-xs text-ink-3">Na fila</dt><dd class="font-mono text-xs text-ink">{{ $pending }} pendente{{ $pending === 1 ? '' : 's' }}</dd></div>
                <div><dt class="text-xs text-ink-3">Com erro</dt><dd class="font-mono text-xs text-ink">{{ $failed }}</dd></div>
            </dl>

            <div data-panel-footer class="flex flex-wrap gap-2 border-t border-line px-4 py-3">
                <flux:button :href="route('configuracoes.google.redirect')" icon="arrow-path">Reconectar</flux:button>
                <flux:button variant="danger" wire:click="disconnect" data-confirm="Desconectar o Google? O que já foi enviado continua lá." data-confirm-label="Desconectar" data-confirm-tone="danger">Desconectar</flux:button>
            </div>
        @else
            <p class="p-4 text-sm text-ink-2">Nenhuma conta conectada.</p>

            <div data-panel-footer class="border-t border-line px-4 py-3">
                <flux:button variant="primary" icon="link" :href="route('configuracoes.google.redirect')">Conectar conta Google</flux:button>
            </div>
        @endif
    </x-panel>
</x-settings-page>
