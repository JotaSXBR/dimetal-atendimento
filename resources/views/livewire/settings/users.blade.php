<x-settings-page route="configuracoes.usuarios" title="Usuários" subtitle="Quem atende, em quais setores, e quem pode transferir tickets." width="wide">
    <x-slot:actions>
        <flux:button variant="primary" size="sm" icon="plus" wire:click="newUser">Novo usuário</flux:button>
    </x-slot:actions>

    <div class="mt-6 space-y-5">
        @if ($showForm)
            <x-panel :title="$editingId ? 'Editar usuário' : 'Novo usuário'">
                <form wire:submit="save">
                    @if ($editing)
                        <div class="border-b border-line p-4">
                            <x-avatar-uploader model="photo" action="saveAvatar" :current="$editing->avatarUrl()" :name="$editing->name" shape="round">
                                @if ($editing->avatar_path)
                                    <flux:button size="sm" type="button" variant="ghost" icon="trash" wire:click="removeAvatar" data-confirm="Remover a foto de {{ $editing->name }}?" data-confirm-label="Remover" data-confirm-tone="danger">Remover</flux:button>
                                @endif
                            </x-avatar-uploader>
                        </div>
                    @endif

                    <div class="grid gap-4 p-4 md:grid-cols-2">
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
                    </div>

                    <div class="border-t border-line px-4 py-3">
                        <flux:checkbox.group wire:model="sectorIds" label="Setores">
                            <div class="flex flex-wrap gap-4">
                                @foreach ($sectors as $sector)
                                    <flux:checkbox :value="$sector->id" :label="$sector->name" />
                                @endforeach
                            </div>
                        </flux:checkbox.group>
                        @error('sectorIds.*') <p class="mt-1 text-xs text-danger">{{ $message }}</p> @enderror
                    </div>

                    <div class="border-t border-line px-4 py-3">
                        <flux:switch wire:model="canTransfer" label="Pode transferir tickets" />
                    </div>

                    <div class="flex gap-2 border-t border-line px-4 py-3">
                        <flux:button type="submit" variant="primary" size="sm">Salvar</flux:button>
                        <flux:button type="button" size="sm" variant="ghost" wire:click="cancel">Cancelar</flux:button>
                    </div>
                </form>
            </x-panel>
        @endif

        <x-panel title="Equipe" :subtitle="$users->count().' '.($users->count() === 1 ? 'pessoa' : 'pessoas')">
            {{-- Colunas só no 2xl (em 1280 a coluna de conteúdo não comporta 4 colunas: o nome sumia). Subgrid alinha as
                 colunas `auto` entre as linhas. Cabeçalho só visual: cada linha já diz tudo. --}}
            <div class="2xl:grid 2xl:grid-cols-[minmax(16rem,2fr)_minmax(0,1fr)_auto_auto] 2xl:gap-x-4">
                <div data-test="users-columns" aria-hidden="true" class="hidden border-b border-line bg-canvas px-4 py-2 text-xs font-semibold text-ink-3 2xl:col-span-full 2xl:grid 2xl:grid-cols-subgrid">
                    <span>Usuário</span>
                    <span>Setores</span>
                    <span>Tickets</span>
                    <span></span>
                </div>

                <ul class="2xl:col-span-full 2xl:grid 2xl:grid-cols-subgrid">
                    @foreach ($users as $user)
                        @php
                            $tickets = $user->open_tickets_count === 0
                                ? 'sem tickets abertos'
                                : $user->open_tickets_count.' '.($user->open_tickets_count === 1 ? 'ticket aberto' : 'tickets abertos');
                        @endphp
                        <li class="flex flex-wrap items-center gap-3 border-b border-line px-4 py-3 last:border-b-0 2xl:col-span-full 2xl:grid 2xl:grid-cols-subgrid" wire:key="user-{{ $user->id }}">
                            <div class="flex min-w-0 grow basis-64 items-center gap-3">
                                <x-avatar :src="$user->avatarUrl()" :name="$user->name" shape="round" size="sm" decorative />
                                <div class="min-w-0 flex-1">
                                    <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm font-semibold text-ink">
                                        <span data-test="user-name" class="max-w-full truncate">{{ $user->name }}</span>
                                        <x-status-badge tone="neutral">{{ $user->role->label() }}</x-status-badge>
                                        @unless ($user->active)
                                            <x-status-badge tone="neutral">Inativo</x-status-badge>
                                        @endunless
                                    </p>
                                    <p class="text-xs wrap-anywhere text-ink-3">
                                        {{ $user->email }}
                                        <span class="2xl:hidden">
                                            @if ($user->sectors->isNotEmpty())
                                                · {{ $user->sectors->pluck('name')->implode(', ') }}
                                            @endif
                                            · {{ $tickets }}
                                        </span>
                                    </p>
                                </div>
                            </div>

                            <p class="hidden truncate text-xs text-ink-2 2xl:block">{{ $user->sectors->pluck('name')->implode(', ') }}</p>
                            <p class="hidden text-xs whitespace-nowrap text-ink-3 2xl:block">{{ $tickets }}</p>

                            <div class="ms-auto flex flex-wrap items-center gap-2 2xl:ms-0">
                                @can('update', $user)
                                    <flux:button size="xs" icon="pencil-square" wire:click="edit({{ $user->id }})">Editar</flux:button>

                                    @if ($user->active)
                                        @can('deactivate', $user)
                                            <flux:button size="xs" variant="subtle" wire:click="toggleActive({{ $user->id }})" data-confirm="Desativar {{ $user->name }}? A pessoa é desconectada agora{{ $user->open_tickets_count > 0 ? ' e os '.$user->open_tickets_count.' tickets abertos dela continuam atribuídos a ela até alguém reatribuir' : '' }}." data-confirm-label="Desativar" data-confirm-tone="danger">Desativar</flux:button>
                                        @endcan
                                    @else
                                        <flux:button size="xs" variant="subtle" wire:click="toggleActive({{ $user->id }})">Reativar</flux:button>
                                    @endif
                                @endcan
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        </x-panel>
    </div>
</x-settings-page>
