{{-- Linha "Conversa" do bloco Etiquetas do painel: chips redondos (com remover) e o seletor do que ainda falta. Some se não há o que mostrar nem aplicar. --}}
<div x-data="focusTagPicker" x-on:tag-removed.self="focus">
    @if ($applied->isNotEmpty() || ($editable && $available->isNotEmpty()))
        <div data-testid="ticket-tags" class="flex items-start gap-2">
            <span data-testid="tags-row-conversa" class="w-14 shrink-0 pt-1.5 text-xs text-ink-3">Conversa</span>
            <div class="flex min-w-0 flex-1 flex-wrap items-center gap-1.5">
                @foreach ($applied as $tag)
                    <span class="inline-flex items-center gap-0.5" wire:key="ticket-tag-{{ $tag->id }}">
                        <x-tag-chip :tag="$tag" :conversation="true" :title="$tag->name" />
                        @if ($editable)
                            <flux:button size="xs" variant="subtle" icon="x-mark" inset wire:click="removeTag({{ $tag->id }})" aria-label="Remover etiqueta {{ $tag->name }}" />
                        @endif
                    </span>
                @endforeach
                @if ($editable && $available->isNotEmpty())
                    <x-tag-picker :tags="$available" label="Adicionar etiqueta à conversa" method="addTag" placeholder="Adicionar…" class="w-full" />
                @endif
            </div>
        </div>
    @endif
</div>
