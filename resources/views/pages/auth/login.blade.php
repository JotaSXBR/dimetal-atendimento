<x-layouts::auth :title="__('Entrar')">
    <div class="flex flex-col gap-6">
        <div>
            <h1 class="text-xl font-semibold tracking-tight">Entrar</h1>
            <p class="mt-1 text-sm text-ink-2">Use o acesso criado pelo administrador</p>
        </div>

        <x-auth-session-status class="text-center" :status="session('status')" />

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-4">
            @csrf

            <x-ui.field
                name="email"
                label="E-mail"
                type="email"
                autocomplete="email"
                autofocus
                required
            />

            <x-ui.field
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

            <x-ui.button variant="primary" type="submit" class="w-full" data-test="login-button">
                Entrar
            </x-ui.button>
        </form>

        <p class="text-xs leading-relaxed text-ink-3">
            O acesso é criado pelo administrador. Precisa de um novo? Fale com Rafael Costa.
        </p>
    </div>
</x-layouts::auth>
