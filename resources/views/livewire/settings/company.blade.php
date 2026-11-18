<x-settings-page route="configuracoes.empresa" title="Empresa e assinatura" subtitle="Nome, logotipo e assinatura que aparecem no sistema e nas mensagens.">
    <div class="mt-6 space-y-5">
        <x-panel title="Logotipo" subtitle="Aparece no menu, na tela de login e no centro do QR do WhatsApp. Imagem quadrada com cantos arredondados; sem logotipo, usamos a inicial do nome.">
            <div class="p-4">
                <x-avatar-uploader model="photo" action="saveLogo" :current="\App\Settings\CompanySettings::logoUrl()" :name="$name" shape="rounded" single accent>
                    @if (\App\Settings\CompanySettings::logoUrl())
                        <flux:button size="sm" type="button" variant="ghost" icon="trash" wire:click="removeLogo" data-confirm="Remover o logotipo da empresa?" data-confirm-label="Remover" data-confirm-tone="danger">Remover</flux:button>
                    @endif
                </x-avatar-uploader>
            </div>
        </x-panel>

        <x-panel title="Nome e assinatura">
            <form wire:submit="save">
                <div class="border-b border-line p-4">
                    <flux:input label="Nome fantasia" wire:model.live.debounce.300ms="name" />
                </div>

                <div data-switch-row class="flex items-center justify-between gap-3 border-b border-line px-4 py-3">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-ink">Assinatura das mensagens</p>
                        <p class="text-xs text-ink-3">Adicionada às mensagens enviadas pelo painel. Não vale para a Automação, legendas de arquivo nem reenvios.</p>
                    </div>
                    <flux:switch wire:model.live="signatureEnabled" aria-label="Assinatura ligada" />
                </div>

                <div x-data="signatureToolbar" class="border-b border-line p-4">
                    <div class="mb-1 flex gap-1" role="toolbar" aria-label="Formatação">
                        <flux:button type="button" size="sm" variant="subtle" x-on:click="wrap('*')" aria-label="Negrito"><span class="font-bold">B</span></flux:button>
                        <flux:button type="button" size="sm" variant="subtle" x-on:click="wrap('_')" aria-label="Itálico"><span class="italic">I</span></flux:button>
                        <flux:button type="button" size="sm" variant="subtle" x-on:click="wrap('~')" aria-label="Tachado"><span class="line-through">S</span></flux:button>
                        <flux:button type="button" size="sm" variant="subtle" x-on:click="wrap('```')" aria-label="Monoespaçado"><span class="font-mono">M</span></flux:button>
                    </div>
                    <div x-ref="template"><flux:textarea rows="3" aria-label="Modelo da assinatura" wire:model.live.debounce.300ms="signatureTemplate" /></div>
                    <p class="mt-1 text-xs text-ink-3">Variáveis: <code>@{{atendente}}</code> <code>@{{setor}}</code> <code>@{{empresa}}</code>. O WhatsApp não tem sublinhado.</p>
                    @error('signatureTemplate')
                        <p class="mt-1 text-xs text-danger" role="alert">{{ $message }}</p>
                    @enderror
                </div>

                <div class="border-b border-line p-4">
                    <flux:select wire:model.live="signaturePosition" label="Posição">
                        <flux:select.option value="inicio">No início da mensagem</flux:select.option>
                        <flux:select.option value="fim">No fim da mensagem</flux:select.option>
                    </flux:select>
                </div>

                <div class="p-4">
                    <div data-preview class="rounded-lg bg-surface-2 p-3 text-sm">
                        <p class="text-xs text-ink-3">Prévia</p>
                        <p class="mt-1 whitespace-pre-wrap">{{ $this->preview() }}</p>
                    </div>
                </div>

                <div data-panel-footer class="flex items-center gap-3 border-t border-line px-4 py-3">
                    <flux:button type="submit" variant="primary">Salvar alterações</flux:button>
                    @if ($saved)
                        <p class="text-sm text-ink-2" role="status">Salvo.</p>
                    @endif
                </div>
            </form>
        </x-panel>
    </div>
</x-settings-page>
