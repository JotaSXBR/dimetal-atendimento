<section class="mx-auto grid w-full max-w-6xl gap-6 p-6 lg:grid-cols-[1fr_22rem]">
    <div>
        <x-page-header title="Contatos" subtitle="Quem fala com a Dimetal. Edite o nome e os dados; contatos editados vão para o Google Contacts.">
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

        <div class="mt-4">
            <flux:input icon="magnifying-glass" placeholder="Buscar por nome, telefone, e-mail ou empresa" wire:model.live.debounce.300ms="search" clearable />
        </div>

        <ul class="mt-4 divide-y divide-line rounded-[14px] border border-line bg-surface">
            @forelse ($contacts as $contact)
                <li wire:key="contact-{{ $contact->id }}">
                    <button type="button" wire:click="select({{ $contact->id }})" class="flex w-full items-center gap-3 px-4 py-3 text-start hover:bg-surface-2 {{ $selectedId === $contact->id ? 'bg-surface-2' : '' }}">
                        <flux:avatar :name="$contact->label()" size="sm" />
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-semibold text-ink">{{ $contact->label() }}</span>
                            <span class="block truncate text-xs text-ink-3">
                                {{ $contact->phoneLabel() }}@if ($contact->company) · {{ $contact->company }}@endif
                            </span>
                        </span>
                    </button>
                </li>
            @empty
                <li class="px-4 py-6 text-center text-sm text-ink-3">Nenhum contato encontrado.</li>
            @endforelse
        </ul>

        <div class="mt-4">{{ $contacts->links() }}</div>
    </div>

    <aside class="rounded-[14px] border border-line bg-surface p-4">
        @if ($selectedId)
            <livewire:contacts.contact-card :contact-id="$selectedId" :key="'contact-'.$selectedId" />
        @else
            <p class="text-sm text-ink-3">Selecione um contato para ver e editar a ficha.</p>
        @endif
    </aside>
</section>
