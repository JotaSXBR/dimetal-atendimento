{{-- Primeiros passos: tela limpa (sem a barra lateral), com a marca da empresa e o Sair. Carrega o mesmo JS do app (Livewire, Echo). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-canvas text-ink antialiased">
        <header class="mx-auto flex w-full max-w-3xl items-center justify-between gap-3 px-6 pt-6">
            <div class="flex min-w-0 items-center gap-2.5">
                <x-avatar :src="\App\Settings\CompanySettings::logoUrl()" :name="\App\Settings\CompanySettings::name()" shape="rounded" size="sm" single accent decorative />
                <span class="truncate text-sm font-semibold tracking-tight">{{ \App\Settings\CompanySettings::name() }}</span>
            </div>

            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <flux:button type="submit" variant="ghost" size="sm" icon="arrow-right-start-on-rectangle" data-test="logout-button">Sair</flux:button>
            </form>
        </header>

        <main role="main">
            {{ $slot }}
        </main>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
