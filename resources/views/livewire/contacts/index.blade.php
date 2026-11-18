<section class="mx-auto grid w-full max-w-[120rem] grid-cols-[minmax(0,1fr)] gap-6 p-6">
    <div>
        <x-page-header title="Contatos" subtitle="Quem fala com a empresa. Edite o nome e os dados; contatos editados vão para o Google Contacts.">
            <flux:button variant="primary" icon="plus" wire:click="$toggle('showNew')">Novo contato</flux:button>
        </x-page-header>

        @if ($showNew)
            <form wire:submit="createContact" class="mt-4 grid gap-3 rounded-[14px] border border-line bg-surface p-4 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                <flux:input label="Telefone" placeholder="(16) 99752-4950" wire:model="newPhone" />
                <flux:input label="Nome" wire:model="newName" />
                <flux:button type="submit" variant="primary">Cadastrar</flux:button>
                @error('newPhone')
                    <p class="text-xs text-danger sm:col-span-3" role="alert">{{ $message }}</p>
                @enderror
                @error('newName')
                    <p class="text-xs text-danger sm:col-span-3" role="alert">{{ $message }}</p>
                @enderror
            </form>
        @endif

        @if ($notice !== '')
            <flux:callout class="mt-4" icon="information-circle" variant="warning" :text="$notice" />
        @endif

        <div class="mt-4 flex flex-col gap-3 sm:flex-row">
            <flux:input icon="magnifying-glass" aria-label="Buscar contatos" placeholder="Buscar por nome, telefone, e-mail ou empresa" wire:model.live.debounce.300ms="search" clearable class="sm:flex-1" />
            @if ($tags->isNotEmpty())
                <div class="sm:w-80 sm:flex-none">
                    <x-tag-picker :tags="$tags" label="Filtrar por etiqueta" model="tagId" :selected="$tagId" placeholder="Todas as etiquetas" />
                </div>
            @endif
        </div>

        <x-skeleton-rows :rows="4" wire:loading.delay wire:target="search" class="mt-4 rounded-xl border border-line bg-surface" />

        {{-- Células com `px-4!`: o Flux zera o padding da primeira e da última coluna, e aqui a tabela fica dentro do cartão. --}}
        <div wire:loading.remove.delay wire:target="search" class="mt-4 overflow-hidden rounded-xl border border-line bg-surface">
            <flux:table data-testid="contacts-table" class="min-w-[52rem]">
                <flux:table.columns class="bg-canvas">
                    <flux:table.column class="px-4! border-line!"><span class="text-xs font-semibold text-ink-3">Contato</span></flux:table.column>
                    <flux:table.column class="w-40 px-4! border-line!"><span class="text-xs font-semibold text-ink-3">Telefone</span></flux:table.column>
                    <flux:table.column class="w-60 px-4! border-line!"><span class="text-xs font-semibold text-ink-3">Etiquetas</span></flux:table.column>
                    <flux:table.column class="w-44 px-4! border-line!"><span class="sr-only">Ações</span></flux:table.column>
                </flux:table.columns>
                <flux:table.rows>
                    @forelse ($contacts as $contact)
                        <flux:table.row wire:key="contact-{{ $contact->id }}" class="relative hover:bg-muted {{ $selectedId === $contact->id ? 'bg-muted' : '' }}">
                            <flux:table.cell class="px-4! py-[11px] border-line!">
                                {{-- Área de clique esticada: o ::after cobre a linha toda, então qualquer ponto dela abre a ficha. --}}
                                <button type="button" wire:click="select({{ $contact->id }})" class="flex w-full min-w-0 items-center gap-3 text-start after:absolute after:inset-0">
                                    <x-avatar :src="$contact->avatarUrl()" :name="$contact->label()" shape="round" size="sm" decorative />
                                    <span class="min-w-0 flex-1">
                                        <span class="block truncate text-[13px] font-medium text-ink">{{ $contact->label() }}</span>
                                        @if ($contact->company)
                                            <span class="block truncate text-xs text-ink-3">{{ $contact->company }}</span>
                                        @endif
                                    </span>
                                </button>
                            </flux:table.cell>
                            <flux:table.cell class="px-4! py-[11px] border-line!"><span class="font-mono text-xs text-ink-2">{{ $contact->phoneLabel() }}</span></flux:table.cell>
                            <flux:table.cell class="relative z-10 px-4! py-[11px] border-line!">
                                <x-contact-chips :contact="$contact" testid="contact-chip" />
                            </flux:table.cell>
                            <flux:table.cell class="relative z-10 px-4! py-[11px] border-line!">
                                <div class="flex items-center justify-end gap-2">
                                    @include('livewire.contacts.chat-button', ['contact' => $contact])
                                    <flux:icon.chevron-right class="size-3.5 text-ink-3" aria-hidden="true" />
                                </div>
                            </flux:table.cell>
                        </flux:table.row>
                    @empty
                        <flux:table.row>
                            <flux:table.cell colspan="4" class="whitespace-normal border-line!">
                                <x-empty-state icon="users" title="Nenhum contato encontrado." description="Tente outro nome ou telefone." />
                            </flux:table.cell>
                        </flux:table.row>
                    @endforelse
                </flux:table.rows>
            </flux:table>
            @if ($contacts->hasPages())
                <div class="border-t border-line px-4 py-3">{{ $contacts->links() }}</div>
            @endif
        </div>
    </div>

    {{-- A ficha abre numa gaveta lateral (igual no celular): a lista fica com a largura toda e não sobra coluna vazia. --}}
    <flux:modal wire:model.self.live="showCard" variant="flyout" position="right" class="w-full max-w-md" data-testid="contact-drawer">
        @if ($selectedId)
            <livewire:contacts.contact-card :contact-id="$selectedId" :key="'contact-'.$selectedId" />
        @endif
    </flux:modal>

    <livewire:inbox.new-conversation />
</section>
