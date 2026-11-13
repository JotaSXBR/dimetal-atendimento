{{-- Paginação do Livewire com os tokens do app e `flux:button` (a original usa a paleta `gray-*` e botões crus). --}}
@php
    $scrollTo ??= 'body';
    $scroll = $scrollTo !== false ? "(\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView()" : '';
    $name = $paginator->getPageName();
@endphp

<div>
    @if ($paginator->hasPages())
        <nav role="navigation" aria-label="{{ __('Pagination Navigation') }}" class="flex items-center justify-between gap-3">
            <p class="hidden text-sm text-ink-2 sm:block">
                {!! __('Showing') !!} <span class="font-medium text-ink">{{ $paginator->firstItem() }}</span> {!! __('to') !!} <span class="font-medium text-ink">{{ $paginator->lastItem() }}</span> {!! __('of') !!} <span class="font-medium text-ink">{{ $paginator->total() }}</span> {!! __('results') !!}
            </p>

            <p class="text-sm text-ink-2 sm:hidden">{{ $paginator->currentPage() }} / {{ $paginator->lastPage() }}</p>

            <div class="flex items-center gap-1">
                <flux:button size="sm" variant="subtle" icon="chevron-left" :disabled="$paginator->onFirstPage()" wire:click="previousPage('{{ $name }}')" x-on:click="{{ $scroll }}" aria-label="{{ __('pagination.previous') }}" />

                @foreach ($elements as $element)
                    @if (is_string($element))
                        <span class="hidden px-2 text-sm text-ink-2 sm:inline">{{ $element }}</span>
                    @else
                        @foreach ($element as $page => $url)
                            <span class="hidden sm:inline" wire:key="paginator-{{ $name }}-page{{ $page }}">
                                @if ($page == $paginator->currentPage())
                                    <flux:button size="sm" variant="filled" aria-current="page" aria-label="Página {{ $page }}">{{ $page }}</flux:button>
                                @else
                                    <flux:button size="sm" variant="subtle" wire:click="gotoPage({{ $page }}, '{{ $name }}')" x-on:click="{{ $scroll }}" aria-label="Ir para a página {{ $page }}">{{ $page }}</flux:button>
                                @endif
                            </span>
                        @endforeach
                    @endif
                @endforeach

                <flux:button size="sm" variant="subtle" icon="chevron-right" :disabled="! $paginator->hasMorePages()" wire:click="nextPage('{{ $name }}')" x-on:click="{{ $scroll }}" aria-label="{{ __('pagination.next') }}" />
            </div>
        </nav>
    @endif
</div>
