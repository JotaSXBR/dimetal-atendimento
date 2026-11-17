@props(['title', 'subtitle' => null])

{{-- Título da página: o mesmo arranjo (título, subtítulo e ação à direita) em todas as telas que não são de tela cheia.
     Sem margem de baixo: todo chamador já dá o espaço até o conteúdo (space-y, gap ou mt-6). --}}
<div data-page-header {{ $attributes->class('flex flex-wrap items-center justify-between gap-3 border-b border-line pb-3.5') }}>
    <div>
        {{-- mb-0!: o Flux põe mb-2 no título seguido de subtítulo; o desenho pede só os 4 px do mt-1. --}}
        <flux:heading size="xl" level="1" class="mb-0!">{{ $title }}</flux:heading>
        @if ($subtitle)
            <flux:subheading class="mt-1 text-ink-2">{{ $subtitle }}</flux:subheading>
        @endif
    </div>

    @if (! $slot->isEmpty())
        <div data-page-header-actions class="flex items-center gap-2">{{ $slot }}</div>
    @endif
</div>
