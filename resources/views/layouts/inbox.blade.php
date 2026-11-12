<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main role="main" class="flex h-[calc(100dvh-3.5rem)] flex-col !p-0 lg:h-dvh">
        <x-alerts-local />
        <livewire:alerts.banner />
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
