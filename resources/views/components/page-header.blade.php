@props(['title', 'subtitle' => null, 'tabs' => null])

{{-- Título da página: o mesmo arranjo (título, subtítulo e ação à direita) em todas as telas que não são de tela cheia.
     Sem margem de baixo: todo chamador já dá o espaço até o conteúdo (space-y, gap ou mt-6).
     Com abas, elas assentam na borda (-mb-px) e o cabeçalho fica sem o pb-3.5. --}}
<div data-page-header {{ $attributes->class(['border-b border-line', 'pb-3.5' => ! $tabs]) }}>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="min-w-0 grow basis-80">
            {{-- mb-0!: o Flux põe mb-2 no título seguido de subtítulo; o desenho pede só os 4 px do mt-1. --}}
            <flux:heading size="xl" level="1" class="mb-0!">{{ $title }}</flux:heading>
            @if ($subtitle)
                <flux:subheading class="mt-1 text-ink-2">{{ $subtitle }}</flux:subheading>
            @endif
        </div>

        @if (! $slot->isEmpty())
            <div data-page-header-actions class="flex flex-wrap items-center gap-2">{{ $slot }}</div>
        @endif
    </div>

    @if ($tabs)
        <div class="mt-3.5">{{ $tabs }}</div>
    @endif
</div>
