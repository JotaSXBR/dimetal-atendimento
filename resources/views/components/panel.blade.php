@props(['title', 'subtitle' => null, 'titleId' => null])

{{-- Painel das configurações: o mesmo da fatia 4 (Relatórios). Markup próprio, não `flux:card` (comparação pelo código
     do Flux 2.21, não medida no navegador): mesmo com `:highlight="false"` e `bg-surface border-line shadow-none`, o card
     seamless tem padding uniforme (24 px no md) e a faixa de cabeçalho exige `body="divided"`, com borda zinc, padding
     24/16 (md) ou 16/12 (xs) e o título no `flux:heading` zinc; seriam sobrescritas em quase toda parte. --}}
<div data-panel {{ $attributes->class('overflow-hidden rounded-xl border border-line bg-surface') }}>
    <div data-panel-header class="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3.5">
        <div class="min-w-0">
            <h2 @if ($titleId) id="{{ $titleId }}" @endif class="break-words text-sm font-semibold text-ink">{{ $title }}</h2>
            @if ($subtitle)
                <p data-panel-subtitle class="text-xs text-ink-3">{{ $subtitle }}</p>
            @endif
        </div>

        @isset($actions)
            <div data-panel-actions class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
        @endisset
    </div>

    {{ $slot }}
</div>
