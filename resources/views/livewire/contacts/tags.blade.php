{{-- Etiquetas do contato na ficha: chips (com remover) e o seletor do que ainda falta. Some o bloco inteiro se não há o que mostrar nem aplicar. --}}
@if ($contactTags->isNotEmpty() || (! $readonly && $availableTags->isNotEmpty()))
    <div data-testid="contact-tags">
        <h3 class="font-mono text-[10px] font-semibold tracking-wider text-ink-3 uppercase">Etiquetas</h3>
        <div class="mt-1 flex flex-wrap items-center gap-1.5">
            @foreach ($contactTags as $tag)
                <span class="inline-flex items-center gap-0.5" wire:key="contact-tag-{{ $tag->id }}">
                    <x-tag-chip :tag="$tag" :title="$tag->name" />
                    @unless ($readonly)
                        <flux:button size="xs" variant="subtle" icon="x-mark" inset wire:click="removeTag({{ $tag->id }})" aria-label="Remover etiqueta {{ $tag->name }}" />
                    @endunless
                </span>
            @endforeach
            @if (! $readonly && $availableTags->isNotEmpty())
                <flux:select size="sm" wire:model.live="addTagId" class="max-w-40" aria-label="Adicionar etiqueta ao contato">
                    <flux:select.option value="">Adicionar etiqueta…</flux:select.option>
                    @foreach ($availableTags as $tag)
                        <flux:select.option :value="$tag->id">{{ $tag->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
        </div>
        @error('tag')
            <p class="mt-1 text-xs text-danger" role="alert">{{ $message }}</p>
        @enderror
    </div>
@endif
