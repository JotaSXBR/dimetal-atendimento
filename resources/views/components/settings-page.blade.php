@props(['route', 'title', 'subtitle' => null, 'width' => '3xl'])

{{-- A rota vem do chamador: numa ação do Livewire a requisição é `livewire/update`, e o nome da rota atual não serve. --}}
@php
    $groups = app(\App\Support\Navigation::class)->settingsGroups(auth()->user());
    $max = match ($width) {
        '6xl' => 'max-w-6xl',
        '5xl' => 'max-w-5xl',
        default => 'max-w-3xl',
    };
@endphp

<div class="mx-auto flex w-full max-w-7xl flex-col gap-4 p-6 lg:flex-row lg:gap-8">
    @if ($groups !== [])
        <nav aria-label="Configuração" class="shrink-0 lg:w-56">
            <div class="flex gap-6 overflow-x-auto lg:flex-col lg:gap-5 lg:overflow-visible">
                @foreach ($groups as $group => $items)
                    <div class="shrink-0">
                        <p data-test="settings-group-title" class="px-3 pb-1 font-mono text-[10px] font-semibold tracking-wider text-ink-3 uppercase">{{ $group }}</p>
                        <ul class="flex gap-1 lg:flex-col">
                            @foreach ($items as $item)
                                <li>
                                    <a
                                        href="{{ route($item->route) }}"
                                        wire:navigate
                                        @if ($item->route === $route) aria-current="page" @endif
                                        class="flex min-h-11 items-center gap-2 rounded-lg px-3 text-sm font-medium whitespace-nowrap transition-colors duration-(--duration-base) ease-(--ease-out) {{ $item->route === $route ? 'bg-accent-soft text-accent-content' : 'text-ink-2 hover:bg-canvas hover:text-ink' }}"
                                    >
                                        <flux:icon :icon="$item->icon" class="size-4 shrink-0" />
                                        {{ $item->label }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach
            </div>
        </nav>
    @endif

    <section class="w-full min-w-0 flex-1 {{ $max }}">
        <x-page-header :title="$title" :subtitle="$subtitle">@isset($actions){{ $actions }}@endisset</x-page-header>

        {{ $slot }}
    </section>
</div>
