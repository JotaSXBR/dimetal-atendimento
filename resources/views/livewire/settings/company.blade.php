<section class="mx-auto w-full max-w-3xl p-6">
    <flux:heading size="xl" level="1">Empresa</flux:heading>
    <flux:subheading>Dados que aparecem nas mensagens e o prazo de primeira resposta usado nos relatórios.</flux:subheading>

    <form wire:submit="save" class="mt-6 space-y-6 rounded-[14px] border border-line bg-surface p-5">
        <flux:input label="Nome fantasia" wire:model="name" />

        <flux:input type="number" min="1" max="1440" label="SLA de primeira resposta (minutos)" wire:model="slaMinutes" />

        <div class="space-y-3 border-t border-line pt-5">
            <div class="flex items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-semibold text-ink">Assinatura das mensagens</p>
                    <p class="text-xs text-ink-3">Adicionada às mensagens enviadas pelo painel. Não vale para o bot, legendas de arquivo nem reenvios.</p>
                </div>
                <flux:switch wire:model="signatureEnabled" aria-label="Assinatura ligada" />
            </div>

            <flux:textarea rows="3" label="Modelo" wire:model="signatureTemplate" />

            <flux:select wire:model="signaturePosition" label="Posição">
                <flux:select.option value="inicio">No início da mensagem</flux:select.option>
                <flux:select.option value="fim">No fim da mensagem</flux:select.option>
            </flux:select>
        </div>

        @if ($saved)
            <p class="text-sm text-ink-2" role="status">Salvo.</p>
        @endif

        <flux:button type="submit" variant="primary">Salvar alterações</flux:button>
    </form>
</section>
