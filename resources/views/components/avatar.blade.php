@props([
    'src' => null,
    'name' => '',
    'shape' => 'round',
    'size' => 'md',
    'single' => false,
    'decorative' => false,
    'accent' => false,
])

@php
    $round = $shape === 'round';
    // O mesmo raio que o `flux:avatar` aplica ao monograma, para a foto cobrir exatamente a moldura dele.
    $radius = $round ? 'rounded-full' : match ($size) {
        'xl' => 'rounded-xl',
        'sm' => 'rounded-md',
        'xs' => 'rounded-sm',
        default => 'rounded-lg',
    };
    // Sem nome não há o que anunciar (e `aria-label=""` seria ruído): o avatar vira decorativo.
    $decorative = $decorative || trim((string) $name) === '';
    $initials = $single && trim((string) $name) !== '' ? mb_strtoupper(mb_substr(trim((string) $name), 0, 1)) : null;
@endphp

{{-- O monograma está sempre embaixo; a foto o cobre e, se falhar ao carregar (URL vencida, 404), some e deixa o monograma à vista. --}}
<span
    x-data="{ failed: false }"
    @if ($decorative) aria-hidden="true" @else role="img" aria-label="{{ $name }}" @endif
    {{ $attributes->class('relative inline-flex flex-none') }}
>
    <flux:avatar :name="$name" :initials="$initials" :circle="$round" :size="$size" :color="$accent ? null : 'auto'" :class="$accent ? 'bg-accent text-accent-foreground' : ''" aria-hidden="true" />
    @if ($src)
        <img
            src="{{ $src }}"
            alt=""
            loading="lazy"
            x-show="! failed"
            x-on:error="failed = true"
            class="absolute inset-0 size-full object-cover {{ $radius }}"
        >
    @endif
</span>
