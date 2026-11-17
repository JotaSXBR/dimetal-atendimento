@props(['href' => null, 'title'])

{{-- Cartão-link próprio (visual inspirado no cartão do Flux Pro; código próprio — o Flux free não tem cartão que seja link).
     Com `href`: link com `wire:navigate`; com `wire:click` nos atributos: botão; sem destino: estático, sem seta nem hover.
     O cartão inteiro é o alvo do clique. O slot (linha secundária e selos) só leva elementos de frase (`span`), pois pode cair num `<button>`. --}}
@php
    $tag = $href !== null ? 'a' : (filled($attributes->get('wire:click')) ? 'button' : 'div');
    $interactive = $tag !== 'div';
@endphp
<{{ $tag }}
    @if ($tag === 'a') href="{{ $href }}" wire:navigate @endif
    @if ($tag === 'button') type="button" @endif
    data-link-card
    {{ $attributes->class(['flex w-full min-h-6 items-center gap-2 rounded-lg bg-canvas px-2.5 py-2 text-left text-xs', 'hover:bg-muted' => $interactive]) }}
>
    <span class="block min-w-0 flex-1">
        <span class="block font-mono text-ink-2" data-link-card-title>{{ $title }}</span>
        <span class="block text-ink-3">{{ $slot }}</span>
    </span>
    @if ($interactive)
        <flux:icon.chevron-right variant="micro" class="size-3 shrink-0 text-ink-3" data-link-card-chevron />
    @endif
</{{ $tag }}>
