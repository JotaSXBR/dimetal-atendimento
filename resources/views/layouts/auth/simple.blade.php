<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-canvas text-ink antialiased">
        <div class="flex min-h-svh flex-col items-center justify-center gap-8 p-6 md:justify-between md:px-12 md:py-10">
            <a href="{{ route('home') }}" class="flex items-center gap-2.5 md:self-start" wire:navigate data-auth-brand>
                <x-avatar :src="\App\Settings\CompanySettings::logoUrl()" :name="\App\Settings\CompanySettings::name()" shape="rounded" size="sm" single accent decorative />
                <span class="text-sm font-semibold tracking-tight">{{ \App\Settings\CompanySettings::name() }}</span>
            </a>

            <div class="w-full max-w-[400px] rounded-xl border border-line bg-surface p-7 shadow-xs" data-auth-card>
                {{ $slot }}
            </div>

            <footer class="text-center text-xs leading-relaxed text-ink-3 md:flex md:justify-between md:self-stretch" data-auth-footer>
                <p>{{ \App\Settings\CompanySettings::name() }}</p>
                <p>Todo acesso é registrado na auditoria</p>
            </footer>
        </div>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
