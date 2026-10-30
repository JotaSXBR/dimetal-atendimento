@props(['title', 'subtitle' => null])

{{-- Título da página: o mesmo arranjo (título, subtítulo e ação à direita) em todas as telas que não são de tela cheia. --}}
<div {{ $attributes->class('flex flex-wrap items-center justify-between gap-3') }}>
    <div>
        <flux:heading size="xl" level="1">{{ $title }}</flux:heading>
        @if ($subtitle)
            <flux:subheading>{{ $subtitle }}</flux:subheading>
        @endif
    </div>

    @if (! $slot->isEmpty())
        <div>{{ $slot }}</div>
    @endif
</div>
