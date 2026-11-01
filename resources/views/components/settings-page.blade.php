@props(['route', 'title', 'subtitle' => null, 'width' => '3xl'])

{{-- A rota vem do chamador: numa ação do Livewire a requisição é `livewire/update`, e o nome da rota atual não serve. --}}
@php
    $navigation = app(\App\Support\Navigation::class);
    $current = $navigation->find($route);
    $siblings = $current === null ? [] : $navigation->siblings(auth()->user(), $route);
    $max = match ($width) {
        '6xl' => 'max-w-6xl',
        '5xl' => 'max-w-5xl',
        default => 'max-w-3xl',
    };
@endphp

<section class="mx-auto w-full {{ $max }} p-6">
    @if ($current !== null)
        <p class="text-xs text-ink-3">Configuração › {{ $current->group }}</p>
    @endif

    @if ($siblings !== [])
        <nav aria-label="Páginas de {{ $current->group }}" class="mt-2 mb-4 flex flex-wrap gap-1">
            @foreach ($siblings as $sibling)
                <a
                    href="{{ route($sibling->route) }}"
                    wire:navigate
                    @if ($sibling->route === $route) aria-current="page" @endif
                    class="rounded-full px-3 py-1 text-xs font-medium {{ $sibling->route === $route ? 'bg-ink text-white' : 'text-ink-2 hover:bg-canvas' }}"
                >
                    {{ $sibling->label }}
                </a>
            @endforeach
        </nav>
    @endif

    <x-page-header :title="$title" :subtitle="$subtitle">@isset($actions){{ $actions }}@endisset</x-page-header>

    {{ $slot }}
</section>
