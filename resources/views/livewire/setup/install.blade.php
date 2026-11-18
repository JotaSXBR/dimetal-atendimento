<div class="flex flex-col gap-6">
    <div>
        <h1 class="text-xl font-semibold tracking-tight">Instalação</h1>
        <p class="mt-1 text-sm text-ink-2">Crie o primeiro administrador</p>
    </div>

    @if ($configured)
        <form wire:submit="save" class="flex flex-col gap-4">
            <flux:input name="code" label="Código da instalação" wire:model="code" autocomplete="off" autofocus required />
            <flux:input name="name" label="Nome" wire:model="name" autocomplete="name" required />
            <flux:input name="email" label="E-mail" type="email" wire:model="email" autocomplete="email" required />
            <flux:input name="password" label="Senha" type="password" wire:model="password" autocomplete="new-password" required />
            <flux:input name="password_confirmation" label="Confirmar senha" type="password" wire:model="password_confirmation" autocomplete="new-password" required />

            <flux:button variant="primary" type="submit" class="w-full" data-test="install-button">
                Criar administrador
            </flux:button>
        </form>
    @else
        <p class="text-sm leading-relaxed text-ink-2">
            Esta instalação não tem um código configurado (<code>SETUP_CODE</code>). Crie o administrador pelo terminal do servidor:
        </p>
        <code class="block rounded-[10px] border border-line bg-surface px-3 py-2 text-xs text-ink">php artisan app:create-user voce@exemplo.com "Seu Nome" --role=admin</code>
    @endif
</div>
