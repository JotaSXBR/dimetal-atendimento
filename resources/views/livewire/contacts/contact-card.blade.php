<form wire:submit="save" class="space-y-3 text-sm">
    <div>
        <h2 class="font-mono text-[10px] font-semibold tracking-wider text-ink-3 uppercase">Contato</h2>
        <p class="mt-1 font-mono text-ink">{{ $contact->phoneLabel() }}</p>
    </div>

    <flux:input size="sm" label="Nome" wire:model="form.name" />
    <flux:input size="sm" label="Apelido (só no sistema)" wire:model="form.nickname" />
    <flux:input size="sm" label="Empresa" wire:model="form.company" />
    <flux:input size="sm" label="Cargo" wire:model="form.jobTitle" />
    <flux:input size="sm" type="email" label="E-mail" wire:model="form.email" />
    <flux:input size="sm" label="Outro telefone" wire:model="form.phone2" />
    <flux:input size="sm" type="date" label="Aniversário" wire:model="form.birthday" />
    <flux:textarea rows="2" label="Endereço" wire:model="form.address" />
    <flux:textarea rows="3" label="Observações" wire:model="form.notes" />

    <div class="space-y-2">
        <p class="text-xs font-medium text-ink-2">Sites</p>
        @foreach ($form->urls as $index => $url)
            <div class="flex items-start gap-2" wire:key="url-{{ $contactId }}-{{ $index }}">
                <div class="flex-1">
                    <flux:input size="sm" placeholder="https://" wire:model="form.urls.{{ $index }}" aria-label="Site {{ $index + 1 }}" />
                    @error('form.urls.'.$index)
                        <p class="mt-1 text-xs text-danger" role="alert">{{ $message }}</p>
                    @enderror
                </div>
                <flux:button size="sm" variant="subtle" icon="x-mark" wire:click="removeUrl({{ $index }})" aria-label="Remover site {{ $index + 1 }}" />
            </div>
        @endforeach
        @if (count($form->urls) < \App\Livewire\Forms\ContactForm::MAX_URLS)
            <flux:button size="xs" variant="subtle" icon="plus" wire:click="addUrl">Adicionar site</flux:button>
        @endif
        @error('form.urls')
            <p class="text-xs text-danger" role="alert">{{ $message }}</p>
        @enderror
    </div>

    <flux:button type="submit" size="sm" variant="primary" class="w-full">Salvar contato</flux:button>

    <div class="flex items-start justify-between gap-2 border-t border-line pt-3 text-xs {{ $google['error'] ? 'text-danger' : 'text-ink-3' }}" @if ($google['error']) role="alert" @endif>
        <span>{{ $google['text'] }}</span>
        @if ($google['error'])
            <flux:button size="xs" variant="subtle" icon="arrow-path" wire:click="resync">Reenviar</flux:button>
        @endif
    </div>
</form>
