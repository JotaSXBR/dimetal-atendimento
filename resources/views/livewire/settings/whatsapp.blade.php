<section class="w-full">
    @include('partials.settings-heading')

    <x-pages::settings.layout heading="WhatsApp" subheading="Conexão do painel com o servidor WAHA">
        <form wire:submit="save" class="my-6 w-full space-y-6">
            <flux:input
                wire:model="baseUrl"
                label="URL interna do WAHA"
                placeholder="http://waha:3000"
                description="Endereço do container WAHA visto pela aplicação, sem barra no final."
                required
            />

            <flux:input
                wire:model="sessionName"
                label="Sessão"
                placeholder="main"
                description="Precisa ser igual a WHATSAPP_DEFAULT_SESSION do container. Use main na produção e beta no ambiente de testes."
                required
            />

            <flux:input
                wire:model="apiKey"
                type="password"
                label="API key"
                :placeholder="$saved ? 'Mantém a atual se ficar vazio' : 'Chave do WAHA_API_KEY'"
            />

            <flux:input
                wire:model="webhookSecret"
                type="password"
                label="Segredo do webhook"
                :placeholder="$saved ? 'Mantém o atual se ficar vazio' : 'Chave do WHATSAPP_HOOK_HMAC_KEY'"
            />

            @if ($errorMessage)
                <p class="rounded-[10px] border border-danger/30 bg-danger/5 px-3 py-2 text-xs text-danger">
                    {{ $errorMessage }}
                </p>
            @elseif ($saved)
                <p class="rounded-[10px] border border-accent/30 bg-accent-soft px-3 py-2 text-xs text-accent-deep">
                    Configuração salva e WAHA respondeu.
                </p>
            @endif

            <div class="flex items-center gap-3">
                <x-ui.button variant="primary" type="submit" wire:loading.attr="disabled">
                    Salvar e testar
                </x-ui.button>
            </div>

            <p class="text-xs leading-relaxed text-ink-3">
                Estes valores precisam bater com as variáveis do container WAHA no Coolify. Veja
                <code class="font-mono">docs/superpowers/specs/waha-config.md</code>.
            </p>
        </form>
    </x-pages::settings.layout>
</section>
