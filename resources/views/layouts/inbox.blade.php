<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main class="flex h-dvh flex-col !p-0">
        <livewire:alerts.banner />
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
