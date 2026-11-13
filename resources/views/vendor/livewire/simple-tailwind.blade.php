{{-- Anterior/Próxima com os tokens do app e `flux:button`. shortcut: só `simplePaginate` (sem cursor), o único usado aqui; se um dia houver cursorPaginate, voltar a tratar `previousCursor()`/`nextCursor()` como na view original do Livewire. --}}
@php
    $scrollTo ??= 'body';
    $scroll = $scrollTo !== false ? "(\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView()" : '';
    $name = $paginator->getPageName();
@endphp

<div>
    @if ($paginator->hasPages())
        <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="flex justify-between gap-2">
            <flux:button size="sm" variant="subtle" icon="chevron-left" :disabled="$paginator->onFirstPage()" wire:click="previousPage('{{ $name }}')" x-on:click="{{ $scroll }}">{!! __('pagination.previous') !!}</flux:button>
            <flux:button size="sm" variant="subtle" icon-trailing="chevron-right" :disabled="! $paginator->hasMorePages()" wire:click="nextPage('{{ $name }}')" x-on:click="{{ $scroll }}">{!! __('pagination.next') !!}</flux:button>
        </nav>
    @endif
</div>
