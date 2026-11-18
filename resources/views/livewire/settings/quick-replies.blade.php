<x-settings-page route="configuracoes.respostas" title="Respostas rápidas" subtitle="Digite / na mensagem para inserir. O texto entra editável: leia o histórico antes de enviar valores ou prazos." width="wide">
    <x-slot:actions>
        <flux:button variant="primary" icon="plus" wire:click="newReply">Nova resposta</flux:button>
    </x-slot:actions>

    <div class="mt-6 space-y-5">
        @if ($showForm)
            <x-panel :title="$editingId ? 'Editar resposta' : 'Nova resposta'">
                <form wire:submit="save">
                    <div class="grid gap-4 p-4 md:grid-cols-2">
                        <flux:input label="Atalho" placeholder="preco" wire:model="shortcut" />
                        <flux:select wire:model="sectorId" label="Setor">
                            <flux:select.option value="">Todos os setores</flux:select.option>
                            @foreach ($sectors as $sector)
                                <flux:select.option :value="$sector->id">{{ $sector->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <div class="md:col-span-2">
                            <flux:textarea rows="4" label="Texto" wire:model="body" />
                        </div>
                        @foreach (['shortcut', 'body', 'sectorId'] as $field)
                            @error($field)
                                <p class="text-xs text-danger md:col-span-2" role="alert">{{ $message }}</p>
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

        <x-panel title="Respostas" :subtitle="$replies->count().' '.($replies->count() === 1 ? 'atalho' : 'atalhos')">
            <ul>
                @forelse ($replies as $reply)
                    <li class="flex items-start gap-3 border-b border-line px-4 py-3 last:border-b-0" wire:key="reply-{{ $reply->id }}">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-semibold text-ink"><code>/{{ $reply->shortcut }}</code> <span class="ms-2 text-xs font-normal text-ink-3">{{ $reply->sector?->name ?? 'Todos os setores' }}</span></p>
                            <p class="mt-1 text-sm whitespace-pre-wrap text-ink-2">{{ $reply->body }}</p>
                        </div>
                        <flux:button size="xs" variant="subtle" icon="pencil-square" wire:click="edit({{ $reply->id }})" aria-label="Editar /{{ $reply->shortcut }}" />
                        <flux:button size="xs" variant="subtle" icon="trash" wire:click="delete({{ $reply->id }})" data-confirm="Apagar esta resposta rápida?" data-confirm-label="Apagar" data-confirm-tone="danger" aria-label="Apagar /{{ $reply->shortcut }}" />
                    </li>
                @empty
                    <li><x-empty-state icon="chat-bubble-bottom-center-text" title="Nenhuma resposta cadastrada." description="Cadastre atalhos para as respostas mais comuns." /></li>
                @endforelse
            </ul>
        </x-panel>
    </div>
</x-settings-page>
