<x-settings-page route="configuracoes.google" title="Google Contatos" subtitle="Os contatos que você edita no sistema são copiados para o Google Contatos da empresa (só de ida). Contato que nunca foi editado não é enviado." width="3xl">
    @if (session('google_error'))
        <flux:callout class="mt-4" variant="danger" icon="exclamation-triangle" :text="session('google_error')" />
    @endif

    <div class="mt-6 rounded-[14px] border border-line bg-surface p-5">
        @if ($connection)
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold text-ink">{{ $connection->account_email }}</p>
                    <p class="text-xs text-ink-3">Conectado em {{ $connection->connected_at?->timezone(config('app.display_timezone'))->format('d/m/Y H:i') }}</p>
                </div>
                <flux:badge :color="$connection->status === \App\Enums\GoogleConnectionStatus::CONNECTED ? 'green' : 'red'">{{ $connection->status->label() }}</flux:badge>
            </div>

            @if ($connection->last_error)
                <p class="mt-3 text-xs text-danger" role="alert">Último erro: {{ $connection->last_error }}</p>
            @endif

            <dl class="mt-4 grid grid-cols-3 gap-3 text-sm">
                <div><dt class="text-xs text-ink-3">Enviados</dt><dd class="font-mono text-ink">{{ $synced }} sincronizado{{ $synced === 1 ? '' : 's' }}</dd></div>
                <div><dt class="text-xs text-ink-3">Na fila</dt><dd class="font-mono text-ink">{{ $pending }} pendente{{ $pending === 1 ? '' : 's' }}</dd></div>
                <div><dt class="text-xs text-ink-3">Com erro</dt><dd class="font-mono text-ink">{{ $failed }}</dd></div>
            </dl>

            <div class="mt-5 flex flex-wrap gap-2">
                <flux:button :href="route('configuracoes.google.redirect')" icon="arrow-path">Reconectar</flux:button>
                <flux:button variant="danger" wire:click="disconnect" wire:confirm="Desconectar o Google? O que já foi enviado continua lá.">Desconectar</flux:button>
            </div>
        @else
            <p class="text-sm text-ink-2">Nenhuma conta conectada.</p>
            <flux:button class="mt-4" variant="primary" icon="link" :href="route('configuracoes.google.redirect')">Conectar conta Google</flux:button>
        @endif
    </div>
</x-settings-page>
