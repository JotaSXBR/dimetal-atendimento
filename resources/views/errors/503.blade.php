<!DOCTYPE html>
{{-- Sem `CompanySettings`, `@auth` ou consulta ao banco: durante uma restauração o banco está sendo trocado. --}}
<html lang="pt-BR">
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1.0" />
        <meta http-equiv="refresh" content="10">
        <title>Em manutenção - {{ config('app.name') }}</title>
        @fonts
        @fluxAppearance
        @vite('resources/css/app.css')
    </head>
    <body class="min-h-screen bg-canvas text-ink antialiased">
        <main class="mx-auto flex min-h-svh w-full max-w-md flex-col items-center justify-center gap-3 p-6 text-center">
            @if (app(\App\Services\Backup\Restore\RestoreProgress::class)->isRunning())
                <h1 class="text-lg font-semibold">Restauração do banco em andamento</h1>
                <p class="text-sm text-ink-2">O sistema volta em instantes. Esta página recarrega sozinha.</p>
            @else
                <h1 class="text-lg font-semibold">Sistema em manutenção</h1>
                <p class="text-sm text-ink-2">Volte em instantes. Esta página recarrega sozinha.</p>
            @endif
        </main>
    </body>
</html>
