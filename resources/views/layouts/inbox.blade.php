<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main role="main" class="flex h-dvh flex-col !p-0">
        <x-alerts-local />
        <livewire:alerts.banner />
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
