<section class="mx-auto w-full max-w-3xl p-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="xl" level="1">Respostas rápidas</flux:heading>
            <flux:subheading>Digite <code>/</code> na mensagem para inserir. O texto entra editável: leia o histórico antes de enviar valores ou prazos.</flux:subheading>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="newReply">Nova resposta</flux:button>
    </div>

    @if ($showForm)
        <form wire:submit="save" class="mt-4 space-y-3 rounded-[14px] border border-line bg-surface p-4">
            <flux:input label="Atalho" placeholder="preco" wire:model="shortcut" />
            <flux:textarea rows="4" label="Texto" wire:model="body" />
            <flux:select wire:model="sectorId" label="Setor">
                <flux:select.option value="">Todos os setores</flux:select.option>
                @foreach ($sectors as $sector)
                    <flux:select.option :value="$sector->id">{{ $sector->name }}</flux:select.option>
                @endforeach
            </flux:select>
            @foreach (['shortcut', 'body', 'sectorId'] as $field)
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
        @forelse ($replies as $reply)
            <li class="flex items-start gap-3 px-4 py-3" wire:key="reply-{{ $reply->id }}">
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-ink"><code>/{{ $reply->shortcut }}</code> <span class="ms-2 text-xs font-normal text-ink-3">{{ $reply->sector?->name ?? 'Todos os setores' }}</span></p>
                    <p class="mt-1 text-sm whitespace-pre-wrap text-ink-2">{{ $reply->body }}</p>
                </div>
                <flux:button size="xs" variant="subtle" icon="pencil-square" wire:click="edit({{ $reply->id }})" aria-label="Editar /{{ $reply->shortcut }}" />
                <flux:button size="xs" variant="subtle" icon="trash" wire:click="delete({{ $reply->id }})" wire:confirm="Apagar esta resposta rápida?" aria-label="Apagar /{{ $reply->shortcut }}" />
            </li>
        @empty
            <li class="px-4 py-6 text-center text-sm text-ink-3">Nenhuma resposta cadastrada.</li>
        @endforelse
    </ul>
</section>
