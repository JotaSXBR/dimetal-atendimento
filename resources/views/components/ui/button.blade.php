@props([
    'variant' => 'primary',
    'type' => 'button',
])

@php
    $variants = [
        'primary' => 'bg-accent text-white hover:bg-accent-deep',
        'secondary' => 'border border-line bg-surface text-ink hover:bg-canvas',
        'ghost' => 'text-ink-2 hover:bg-canvas',
    ];
@endphp

<button
    type="{{ $type }}"
    {{ $attributes->merge([
        'class' => 'inline-flex h-9 items-center justify-center gap-2 rounded-[10px] px-4 text-[13px] font-semibold transition-colors focus:ring-2 focus:ring-accent focus:ring-offset-2 focus:outline-none disabled:opacity-50 '
            .($variants[$variant] ?? $variants['primary']),
    ]) }}
>
    {{ $slot }}
</button>
