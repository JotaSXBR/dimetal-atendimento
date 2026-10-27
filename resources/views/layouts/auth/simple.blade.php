<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-canvas text-ink antialiased">
        <div class="flex min-h-svh flex-col items-center justify-center gap-8 p-6 md:p-10">
            <a href="{{ route('home') }}" class="flex items-center gap-2.5" wire:navigate>
                <span class="flex size-8 items-center justify-center rounded-[10px] bg-accent text-sm font-semibold text-white">
                    D
                </span>
                <span class="text-sm font-semibold tracking-tight">Dimetal Distribuidora</span>
            </a>

            <div class="w-full max-w-[400px] rounded-[14px] border border-line bg-surface p-8 shadow-[0_1px_2px_rgba(16,21,19,0.04)]">
                {{ $slot }}
            </div>

            <footer class="text-center text-xs leading-relaxed text-ink-3">
                <p>Dimetal Distribuidora · CNPJ 12.345.678/0001-90</p>
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
