{{-- Etiquetas do contato na ficha: chips (com remover) e o seletor do que ainda falta. Some o bloco inteiro se não há o que mostrar nem aplicar.
     Com `$rowLabel` (painel do Inbox, dentro do bloco Etiquetas) vira a linha rotulada "Contato"; sem ele, tem o título "Etiquetas" (ficha completa e formulário). --}}
@php($row = $rowLabel ?? null)
@if ($contactTags->isNotEmpty() || (! $readonly && $availableTags->isNotEmpty()))
    <div data-testid="contact-tags" @class(['flex items-start gap-2' => $row])>
        @if ($row)
            <span data-testid="tags-row-contato" class="w-16 shrink-0 pt-1 text-xs text-ink-3">{{ $row }}</span>
        @else
            <h3 class="text-xs font-semibold text-ink-3">Etiquetas</h3>
        @endif
        <div @class(['min-w-0 flex-1' => $row])>
            <div @class(['flex flex-wrap items-center gap-1.5', 'mt-1' => ! $row])>
                @foreach ($contactTags as $tag)
                    <span class="inline-flex items-center gap-0.5" wire:key="contact-tag-{{ $tag->id }}">
                        <x-tag-chip :tag="$tag" :title="$tag->name" />
                        @unless ($readonly)
                            <flux:button size="xs" variant="subtle" icon="x-mark" inset wire:click="removeTag({{ $tag->id }})" aria-label="Remover etiqueta {{ $tag->name }}" />
                        @endunless
                    </span>
                @endforeach
                @if (! $readonly && $availableTags->isNotEmpty())
                    <x-tag-picker :tags="$availableTags" label="Adicionar etiqueta ao contato" method="addTag" class="w-full" />
                @endif
            </div>
            @error('tag')
                <p class="mt-1 text-xs text-danger" role="alert">{{ $message }}</p>
            @enderror
        </div>
    </div>
@endif
