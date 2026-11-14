@props(['label', 'id', 'width' => 'w-80'])

{{-- Popover próprio (visual inspirado no Popover do Flux Pro; código próprio). O botão vem no slot `trigger` e precisa de `data-popover-trigger`,
     `x-on:click="toggle"`, `x-bind:aria-expanded="open"` e `aria-controls="{{ $id }}"`. O estado e o teclado vivem em `Alpine.data('popover')`
     (resources/js/popover.js): Esc (ouvido na janela, só com o painel aberto) fecha só ele e devolve o foco ao botão; clique fora fecha. Campos do painel
     com `wire:model` adiado: fechar com mudança aplica (`$wire.$refresh()`); botões do painel usam `x-on:click="apply"` (aplica e fecha) ou
     `x-on:click="reset"` (descarta e fecha, para o "Limpar" que já manda o servidor ao padrão). --}}
<div x-data="popover" x-on:keydown.escape.window="escape" x-on:click.outside="dismiss" {{ $attributes->class('relative') }}>
    {{ $trigger }}

    <div id="{{ $id }}" data-popover-panel role="dialog" aria-label="{{ $label }}" x-show="open" x-cloak x-on:change="touch" class="absolute end-0 z-30 mt-1 {{ $width }} max-w-[calc(100vw-2rem)] rounded-lg border border-line bg-surface p-3 shadow-lg">
        {{ $slot }}
    </div>
</div>
