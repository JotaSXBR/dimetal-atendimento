<x-settings-page route="configuracoes.etiquetas" title="Etiquetas" subtitle="Etiqueta do contato diz quem é o cliente e acompanha o contato (VIP, revenda); etiqueta da conversa diz o assunto do atendimento. A Automação aplica as duas (bloco Etiquetar) e a fila filtra por elas. Apagar uma etiqueta a remove de todos." width="wide">
    <x-slot:actions>
        <flux:button variant="primary" icon="plus" wire:click="newTag('conversa')">Nova etiqueta</flux:button>
    </x-slot:actions>

    <div class="mt-6 space-y-5">
        @if ($showForm)
            <x-panel :title="$editingId ? 'Editar etiqueta' : 'Nova etiqueta'">
                <form wire:submit="save">
                    <div class="space-y-4 p-4">
                        <flux:input label="Nome" wire:model="name" maxlength="40" />

                        <flux:radio.group wire:model="scope" label="De quem é">
                            @foreach (\App\Enums\TagScope::cases() as $option)
                                <flux:radio :value="$option->value" :label="$option->label()" />
                            @endforeach
                        </flux:radio.group>

                        <fieldset>
                            <legend class="mb-2 text-sm font-medium text-ink">Cor</legend>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($palette as $tone)
                                    <label class="tag-{{ $tone->value }} cursor-pointer" title="{{ $tone->label() }}">
                                        <input type="radio" wire:model="color" value="{{ $tone->value }}" class="peer sr-only" aria-label="{{ $tone->label() }}">
                                        <span class="block size-7 rounded-full bg-[var(--tag-line)] ring-offset-2 ring-offset-surface peer-checked:ring-2 peer-checked:ring-ink peer-focus-visible:ring-2 peer-focus-visible:ring-ink" aria-hidden="true"></span>
                                    </label>
                                @endforeach
                            </div>
                        </fieldset>

                        @foreach (['name', 'scope', 'color'] as $field)
                            @error($field)
                                <p class="text-xs text-danger" role="alert">{{ $message }}</p>
                            @enderror
                        @endforeach
                    </div>
                    <div class="flex gap-2 border-t border-line px-4 py-3">
                        <flux:button type="submit" variant="primary">Salvar</flux:button>
                        <flux:button type="button" variant="ghost" wire:click="cancel">Cancelar</flux:button>
                    </div>
                </form>
            </x-panel>
        @endif

        @foreach ([['scope' => 'contato', 'title' => 'Do contato', 'tags' => $contactTags, 'empty' => 'Nenhuma etiqueta do contato.', 'uses' => 'contacts_count', 'unit' => ['contato', 'contatos']], ['scope' => 'conversa', 'title' => 'Da conversa', 'tags' => $ticketTags, 'empty' => 'Nenhuma etiqueta da conversa.', 'uses' => 'tickets_count', 'unit' => ['ticket', 'tickets']]] as $group)
            <x-panel :title="$group['title']" role="region" :aria-label="$group['title']">
                <x-slot:actions>
                    <flux:button size="xs" variant="subtle" icon="plus" wire:click="newTag('{{ $group['scope'] }}')">Nova</flux:button>
                </x-slot:actions>

                <ul>
                    @forelse ($group['tags'] as $tag)
                        <li class="flex items-center gap-3 border-b border-line px-4 py-3 last:border-b-0" wire:key="tag-{{ $tag->id }}">
                            <x-tag-chip :tag="$tag" :conversation="$group['scope'] === 'conversa'" :title="$tag->name" />
                            <p class="min-w-0 flex-1 text-xs text-ink-3">{{ $tag->{$group['uses']} }} {{ $tag->{$group['uses']} === 1 ? $group['unit'][0] : $group['unit'][1] }}</p>
                            <flux:button size="xs" variant="subtle" icon="pencil-square" wire:click="edit({{ $tag->id }})" aria-label="Editar {{ $tag->name }}" />
                            <flux:button size="xs" variant="subtle" icon="trash" wire:click="delete({{ $tag->id }})" data-confirm="Apagar a etiqueta {{ $tag->name }}?" data-confirm-label="Apagar" data-confirm-tone="danger" aria-label="Apagar {{ $tag->name }}" />
                        </li>
                    @empty
                        <li><x-empty-state compact icon="tag" :title="$group['empty']" /></li>
                    @endforelse
                </ul>
            </x-panel>
        @endforeach
    </div>
</x-settings-page>
