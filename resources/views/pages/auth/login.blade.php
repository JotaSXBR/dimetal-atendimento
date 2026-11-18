<x-layouts::auth :title="__('Entrar')">
    <div class="flex flex-col gap-6">
        <div class="flex flex-col gap-1">
            <h1 class="text-xl font-semibold tracking-tight">Entrar</h1>
            <p class="text-[13px] text-ink-2">Use o acesso criado pelo administrador</p>
        </div>

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-4">
            @csrf

            <flux:input
                name="email"
                label="E-mail"
                type="email"
                :value="old('email')"
                autocomplete="email"
                autofocus
                required
            />

            <flux:input
                name="password"
                label="Senha"
                type="password"
                autocomplete="current-password"
                required
            />

            <flux:checkbox
                name="remember"
                label="Manter conectado"
                :checked="old('remember')"
                class="text-[13px]"
            />

            <flux:button variant="primary" type="submit" class="w-full" data-test="login-button">
                Entrar
            </flux:button>
        </form>

        <div class="flex flex-col gap-1 border-t border-line pt-5" data-auth-help>
            <p class="text-xs font-semibold text-ink">Sem acesso?</p>
            <p class="text-xs leading-relaxed text-ink-3">
                O acesso é criado pelo administrador. Precisa de um novo? Fale com Rafael Costa.
            </p>
        </div>
    </div>
</x-layouts::auth>
