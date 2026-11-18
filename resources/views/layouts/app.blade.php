<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main role="main" data-page-layout>
        <x-alerts-local :inset="true" />
        <livewire:alerts.banner :inset="true" />
        {{ $slot }}
    </flux:main>
</x-layouts::app.sidebar>
