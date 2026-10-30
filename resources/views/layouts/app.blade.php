<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main>
        <x-alerts-local :inset="true" />
        <livewire:alerts.banner :inset="true" />
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
