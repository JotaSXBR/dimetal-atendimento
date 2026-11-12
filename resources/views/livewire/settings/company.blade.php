<x-settings-page route="configuracoes.empresa" title="Empresa e assinatura" subtitle="Nome, logotipo e assinatura que aparecem no sistema e nas mensagens." width="3xl">
    <section class="mt-6 space-y-3 rounded-[14px] border border-line bg-surface p-5" aria-labelledby="logo-title">
        <div>
            <p id="logo-title" class="text-sm font-semibold text-ink">Logotipo</p>
            <p class="text-xs text-ink-3">Aparece no menu, na tela de login e no centro do QR do WhatsApp. Imagem quadrada com cantos arredondados; sem logotipo, usamos a inicial do nome.</p>
        </div>

        <x-avatar-uploader model="photo" action="saveLogo" :current="\App\Settings\CompanySettings::logoUrl()" :name="$name" shape="rounded" single accent>
            @if (\App\Settings\CompanySettings::logoUrl())
                <flux:button size="sm" type="button" variant="ghost" icon="trash" wire:click="removeLogo" wire:confirm="Remover o logotipo da empresa?">Remover</flux:button>
            @endif
        </x-avatar-uploader>
    </section>

    <form wire:submit="save" class="mt-6 space-y-6 rounded-[14px] border border-line bg-surface p-5">
        <flux:input label="Nome fantasia" wire:model.live.debounce.300ms="name" />

        <div class="space-y-3 border-t border-line pt-5">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold text-ink">Assinatura das mensagens</p>
                    <p class="text-xs text-ink-3">Adicionada às mensagens enviadas pelo painel. Não vale para o bot, legendas de arquivo nem reenvios.</p>
                </div>
                <flux:switch wire:model.live="signatureEnabled" aria-label="Assinatura ligada" />
            </div>

            <div x-data="signatureToolbar">
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

            <flux:select wire:model.live="signaturePosition" label="Posição">
                <flux:select.option value="inicio">No início da mensagem</flux:select.option>
                <flux:select.option value="fim">No fim da mensagem</flux:select.option>
            </flux:select>

            <div class="rounded-lg bg-surface-2 p-3 text-sm">
                <p class="text-xs text-ink-3">Prévia</p>
                <p class="mt-1 whitespace-pre-wrap">{{ $this->preview() }}</p>
            </div>
        </div>

        @if ($saved)
            <p class="text-sm text-ink-2" role="status">Salvo.</p>
        @endif

        <flux:button type="submit" variant="primary">Salvar alterações</flux:button>
    </form>
</x-settings-page>
