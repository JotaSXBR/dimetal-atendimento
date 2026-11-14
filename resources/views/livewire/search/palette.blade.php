{{-- Busca Ctrl+K (resources/js/command-palette.js): combobox com listbox; a marca da seta é `data-active` + `aria-activedescendant`. --}}
<div>
    <input type="search" role="combobox" aria-controls="palette-list" aria-expanded="true" aria-autocomplete="list" wire:model.live.debounce.200ms="q"
        placeholder="Buscar tela, contato ou protocolo" aria-label="Buscar tela, contato ou protocolo" autocomplete="off"
        class="h-10 w-full rounded-lg border border-line bg-surface px-3 text-sm text-ink placeholder:text-ink-3">

    <div id="palette-list" data-palette-list role="listbox" aria-label="Resultados" class="mt-3 max-h-80 space-y-3 overflow-y-auto">
        @php($index = 0)
        @foreach (['screens' => 'Telas', 'contacts' => 'Contatos', 'tickets' => 'Tickets'] as $group => $title)
            @if ($results[$group] !== [])
                <div role="group" aria-label="{{ $title }}">
                    <p aria-hidden="true" class="px-2 pb-1 text-xs font-medium text-ink-3">{{ $title }}</p>
                    @foreach ($results[$group] as $row)
                        <a id="palette-option-{{ $index++ }}" href="{{ $row['url'] }}" wire:navigate role="option" data-palette-option class="flex min-h-8 items-center justify-between gap-3 rounded-md px-2 py-1 text-sm text-ink hover:bg-canvas data-[active]:bg-canvas data-[active]:ring-1 data-[active]:ring-line">
                            <span class="truncate">{{ $row['label'] }}</span>
                            @if (isset($row['detail']))
                                <span class="shrink-0 text-xs text-ink-3">{{ $row['detail'] }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            @endif
        @endforeach
    </div>

    @if ($searching && $results['screens'] === [] && $results['contacts'] === [] && $results['tickets'] === [])
        <p role="status" class="px-2 py-3 text-sm text-ink-3">Nada encontrado</p>
    @endif
</div>
