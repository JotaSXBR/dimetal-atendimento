{{-- Etiquetas da conversa no painel: chips redondos (com remover) e o seletor do que ainda falta. Some se não há o que mostrar nem aplicar. --}}
<div>
    @if ($applied->isNotEmpty() || ($editable && $available->isNotEmpty()))
        <div data-testid="ticket-tags" class="mt-4">
            <h3 class="font-mono text-[10px] font-semibold tracking-wider text-ink-3 uppercase">Etiquetas da conversa</h3>
            <div class="mt-1 flex flex-wrap items-center gap-1.5">
                @foreach ($applied as $tag)
                    <span class="inline-flex items-center gap-0.5" wire:key="ticket-tag-{{ $tag->id }}">
                        <x-tag-chip :tag="$tag" :conversation="true" :title="$tag->name" />
                        @if ($editable)
                            <flux:button size="xs" variant="subtle" icon="x-mark" inset wire:click="removeTag({{ $tag->id }})" aria-label="Remover etiqueta {{ $tag->name }}" />
                        @endif
                    </span>
                @endforeach
                @if ($editable && $available->isNotEmpty())
                    <flux:select size="sm" wire:model.live="addTagId" class="max-w-40" aria-label="Adicionar etiqueta à conversa">
                        <flux:select.option value="">Adicionar etiqueta…</flux:select.option>
                        @foreach ($available as $tag)
                            <flux:select.option :value="$tag->id">{{ $tag->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
            </div>
        </div>
    @endif
</div>
