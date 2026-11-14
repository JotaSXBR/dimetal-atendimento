@props(['label', 'id', 'width' => 'w-80'])

{{-- Popover próprio (visual inspirado no Popover do Flux Pro; código próprio). O botão vem no slot `trigger` e precisa de `data-popover-trigger`,
     `x-on:click="toggle"`, `x-bind:aria-expanded="open"` e `aria-controls="{{ $id }}"`. O estado e o teclado vivem em `Alpine.data('popover')`
     (resources/js/popover.js): Esc fecha só ele e devolve o foco ao botão; clique fora fecha. Botões do painel que fecham usam `x-on:click="close"`. --}}
<div x-data="popover" x-on:keydown.escape="escape" x-on:click.outside="dismiss" {{ $attributes->class('relative') }}>
    {{ $trigger }}

    <div id="{{ $id }}" data-popover-panel role="dialog" aria-label="{{ $label }}" x-show="open" x-cloak class="absolute end-0 z-30 mt-1 {{ $width }} max-w-[calc(100vw-2rem)] rounded-lg border border-line bg-surface p-3 shadow-lg">
        {{ $slot }}
    </div>
</div>
