<x-settings-page route="configuracoes.etiquetas" title="Etiquetas" subtitle="Marcam tickets (pelo bot ou à mão) e filtram a fila. Apagar uma etiqueta a remove de todos os tickets." width="3xl">
    <x-slot:actions>
        <flux:button variant="primary" icon="plus" wire:click="newTag">Nova etiqueta</flux:button>
    </x-slot:actions>

    @if ($showForm)
        <form wire:submit="save" class="mt-4 space-y-3 rounded-[14px] border border-line bg-surface p-4">
            <flux:input label="Nome" wire:model="name" maxlength="40" />
            <flux:input type="color" label="Cor" wire:model="color" />
            @foreach (['name', 'color'] as $field)
                @error($field)
                    <p class="text-xs text-danger" role="alert">{{ $message }}</p>
                @enderror
            @endforeach
            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">Salvar</flux:button>
                <flux:button type="button" variant="subtle" wire:click="cancel">Cancelar</flux:button>
            </div>
        </form>
    @endif

    <ul class="mt-6 divide-y divide-line rounded-[14px] border border-line bg-surface">
        @forelse ($tags as $tag)
            <li class="flex items-center gap-3 px-4 py-3" wire:key="tag-{{ $tag->id }}">
                <span class="size-3 rounded-full" style="background-color: {{ $tag->color }}" aria-hidden="true"></span>
                <p class="min-w-0 flex-1 text-sm font-semibold text-ink">{{ $tag->name }} <span class="ms-2 text-xs font-normal text-ink-3">{{ $tag->tickets_count }} tickets</span></p>
                <flux:button size="xs" variant="subtle" icon="pencil-square" wire:click="edit({{ $tag->id }})" aria-label="Editar {{ $tag->name }}" />
                <flux:button size="xs" variant="subtle" icon="trash" wire:click="delete({{ $tag->id }})" wire:confirm="Apagar a etiqueta {{ $tag->name }}?" aria-label="Apagar {{ $tag->name }}" />
            </li>
        @empty
            <li class="px-4 py-6 text-center text-sm text-ink-3">Nenhuma etiqueta cadastrada.</li>
        @endforelse
    </ul>
</x-settings-page>
