<section class="mx-auto w-full max-w-6xl p-6">
    <x-page-header title="Usuários" subtitle="Quem atende, em quais setores, e quem pode transferir tickets.">
        <flux:button variant="primary" size="sm" icon="plus" wire:click="newUser">Novo usuário</flux:button>
    </x-page-header>

    @if ($showForm)
        <form wire:submit="save" class="mt-6 grid gap-4 rounded-[14px] border border-line bg-surface p-5 md:grid-cols-2">
            <div>
                <flux:input wire:model="name" label="Nome" maxlength="120" />
                @error('name') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
            </div>

            <div>
                <flux:input wire:model="email" type="email" label="E-mail" maxlength="255" />
                @error('email') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
            </div>

            <div>
                <flux:select wire:model="role" label="Papel">
                    @foreach ($roles as $option)
                        <flux:select.option :value="$option->value">{{ $option->label() }}</flux:select.option>
                    @endforeach
                </flux:select>
                @error('role') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
            </div>

            <div>
                <flux:input wire:model="password" type="password" :label="$editingId ? 'Nova senha (deixe em branco para manter)' : 'Senha'" autocomplete="new-password" />
                @error('password') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
            </div>

            <div class="md:col-span-2">
                <flux:checkbox.group wire:model="sectorIds" label="Setores">
                    <div class="flex flex-wrap gap-4">
                        @foreach ($sectors as $sector)
                            <flux:checkbox :value="$sector->id" :label="$sector->name" />
                        @endforeach
                    </div>
                </flux:checkbox.group>
                @error('sectorIds.*') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
            </div>

            <div class="md:col-span-2">
                <flux:switch wire:model="canTransfer" label="Pode transferir tickets" />
            </div>

            <div class="flex gap-2 md:col-span-2">
                <flux:button type="submit" variant="primary" size="sm">Salvar</flux:button>
                <flux:button type="button" size="sm" variant="subtle" wire:click="cancel">Cancelar</flux:button>
            </div>
        </form>
    @endif

    <ul class="mt-6 divide-y divide-line rounded-[14px] border border-line bg-surface">
        @foreach ($users as $user)
            <li class="flex flex-wrap items-center gap-3 px-4 py-3" wire:key="user-{{ $user->id }}">
                <div class="min-w-0 flex-1">
                    <p class="flex items-center gap-2 text-sm font-semibold text-ink">
                        <span class="truncate">{{ $user->name }}</span>
                        <flux:badge size="sm">{{ $user->role->label() }}</flux:badge>
                        @unless ($user->active)
                            <flux:badge size="sm" color="zinc">Inativo</flux:badge>
                        @endunless
                    </p>
                    <p class="truncate text-xs text-ink-3">
                        {{ $user->email }}
                        @if ($user->sectors->isNotEmpty())
                            · {{ $user->sectors->pluck('name')->implode(', ') }}
                        @endif
                        ·
                        @if ($user->open_tickets_count === 0)
                            sem tickets abertos
                        @else
                            {{ $user->open_tickets_count }} {{ $user->open_tickets_count === 1 ? 'ticket aberto' : 'tickets abertos' }}
                        @endif
                    </p>
                </div>

                @can('update', $user)
                    <flux:button size="xs" icon="pencil-square" wire:click="edit({{ $user->id }})">Editar</flux:button>

                    @if ($user->active)
                        @can('deactivate', $user)
                            <flux:button size="xs" variant="subtle" wire:click="toggleActive({{ $user->id }})" wire:confirm="Desativar {{ $user->name }}? A pessoa é desconectada agora{{ $user->open_tickets_count > 0 ? ' e os '.$user->open_tickets_count.' tickets abertos dela continuam atribuídos a ela até alguém reatribuir' : '' }}.">Desativar</flux:button>
                        @endcan
                    @else
                        <flux:button size="xs" variant="subtle" wire:click="toggleActive({{ $user->id }})">Reativar</flux:button>
                    @endif
                @endcan
            </li>
        @endforeach
    </ul>
</section>
