<x-settings-page route="configuracoes.setores" title="Setores" subtitle="Ativo aparece no menu e recebe tickets. Fora do menu não aparece no menu, mas continua recebendo transferências. Desativado não recebe nada novo; o histórico permanece. Só dá para apagar um setor sem uso." width="3xl">
    <form wire:submit="create($wire.newName)" class="mt-6 flex flex-wrap items-end gap-2 rounded-[14px] border border-line bg-surface p-4">
        <div class="min-w-48 flex-1">
            <flux:input wire:model="newName" label="Novo setor" placeholder="Ex.: Logística" maxlength="60" autocomplete="off" />
        </div>
        <flux:button type="submit" variant="primary" size="sm" icon="plus">Criar setor</flux:button>
        @error('newName')
            <p class="basis-full text-xs text-danger" role="alert">{{ $message }}</p>
        @enderror
    </form>

    <ul class="mt-4 divide-y divide-line rounded-[14px] border border-line bg-surface">
        @foreach ($sectors as $sector)
            <li class="px-4 py-3" wire:key="sector-{{ $sector->id }}">
                <div class="flex flex-wrap items-center gap-3">
                    <div class="flex flex-col">
                        <flux:button size="xs" variant="subtle" icon="chevron-up" wire:click="move({{ $sector->id }}, 'up')" aria-label="Subir {{ $sector->name }}" />
                        <flux:button size="xs" variant="subtle" icon="chevron-down" wire:click="move({{ $sector->id }}, 'down')" aria-label="Descer {{ $sector->name }}" />
                    </div>

                    <div class="min-w-36 flex-1" x-data="{ editing: false, name: @js($sector->name) }">
                        <p x-show="! editing" class="flex items-center gap-1 text-sm font-semibold text-ink">
                            <span class="me-1 font-mono text-xs text-ink-3">{{ $loop->iteration }}</span>
                            <span class="truncate">{{ $sector->name }}</span>
                            <flux:button size="xs" variant="subtle" icon="pencil-square" x-on:click="editing = true; $nextTick(() => $refs.field.focus())" aria-label="Renomear {{ $sector->name }}" />
                        </p>
                        <form x-show="editing" x-cloak class="flex items-center gap-1" x-on:submit.prevent="$wire.rename({{ $sector->id }}, name).then(() => editing = false)" x-on:keydown.escape="editing = false; name = @js($sector->name)">
                            <input x-ref="field" x-model="name" maxlength="60" aria-label="Novo nome de {{ $sector->name }}" class="min-w-0 flex-1 rounded-lg border border-line bg-surface px-2 py-1 text-sm text-ink">
                            <flux:button type="submit" size="xs" variant="primary">Salvar</flux:button>
                            <flux:button type="button" size="xs" variant="subtle" x-on:click="editing = false; name = @js($sector->name)">Cancelar</flux:button>
                        </form>
                    </div>

                    <div class="w-44">
                        <flux:select :value="$sector->status->value" wire:change="setStatus({{ $sector->id }}, $event.target.value)" aria-label="Estado de {{ $sector->name }}">
                            @foreach ($statuses as $status)
                                <flux:select.option :value="$status->value" :selected="$status === $sector->status">{{ $status->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>

                    <flux:button size="xs" variant="subtle" icon="trash" wire:click="remove({{ $sector->id }})" wire:confirm="Apagar o setor {{ $sector->name }}? Os tickets antigos continuam mostrando o nome." aria-label="Apagar {{ $sector->name }}" />
                </div>

                @error('rename.'.$sector->id)
                    <p class="mt-2 text-xs text-danger" role="alert">{{ $message }}</p>
                @enderror
                @error('remove.'.$sector->id)
                    <p class="mt-2 text-xs text-danger" role="alert">{{ $message }}</p>
                @enderror
                @error('status.'.$sector->id)
                    <p class="mt-2 text-xs text-danger" role="alert">{{ $message }}</p>
                @enderror
            </li>
        @endforeach
    </ul>
</x-settings-page>
