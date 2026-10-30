<div>
@php($tz = config()->string('app.display_timezone'))
@if ($compact && ! $editing)
    <div class="space-y-4 text-sm">
        <div>
            <h2 class="font-mono text-[10px] font-semibold tracking-wider text-ink-3 uppercase">Cliente</h2>
            <p class="mt-1 font-semibold text-ink">{{ $contact->label() }}</p>
            @if ($contact->company || $contact->job_title)
                <p class="text-ink-2">{{ implode(' · ', array_filter([$contact->company, $contact->job_title])) }}</p>
            @endif
            <p class="font-mono text-xs text-ink-2">{{ $contact->phoneLabel() }}</p>
            @if ($contact->email)
                <p class="truncate text-xs text-ink-2">{{ $contact->email }}</p>
            @endif
        </div>

        <div>
            <h3 class="font-mono text-[10px] font-semibold tracking-wider text-ink-3 uppercase">Observações</h3>
            @if ($contact->notes)
                <p class="mt-1 text-xs break-words whitespace-pre-wrap text-ink">{{ $contact->notes }}</p>
            @else
                <p class="mt-1 text-xs text-ink-3">Nenhuma observação.</p>
            @endif
        </div>

        <div>
            <h3 class="font-mono text-[10px] font-semibold tracking-wider text-ink-3 uppercase">Atendimentos anteriores</h3>
            <ul class="mt-2 space-y-2">
                @forelse ($history as $past)
                    <li class="text-xs" wire:key="history-{{ $past->id }}">
                        @if ($past->status === \App\Enums\TicketStatus::ENCERRADO)
                            <a href="{{ route('historico', ['ticketId' => $past->id]) }}" wire:navigate class="font-mono text-ink underline-offset-2 hover:underline">{{ $past->protocol }}</a>
                        @else
                            <span class="font-mono text-ink">{{ $past->protocol }}</span>
                        @endif
                        <span class="text-ink-3">· {{ $past->opened_at->timezone($tz)->format('d/m/Y') }} · {{ $past->sector?->name ?? 'Fila geral' }}</span>
                        <p class="text-ink-2">{{ $past->status->label() }}@if ($past->close_outcome) · {{ $past->close_outcome->label() }}@endif</p>
                    </li>
                @empty
                    <li class="text-xs text-ink-3">Nenhum atendimento ainda.</li>
                @endforelse
            </ul>
        </div>

        @if ($google['error'])
            <div class="flex items-start justify-between gap-2 text-xs text-danger" role="alert">
                <span>{{ $google['text'] }}</span>
                <flux:button size="xs" variant="subtle" icon="arrow-path" wire:click="resync">Reenviar</flux:button>
            </div>
        @endif

        @unless ($readonly)
            <flux:button size="sm" variant="subtle" icon="pencil-square" class="w-full" wire:click="toggleEdit">Editar contato</flux:button>
        @endunless
    </div>
@else
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
    @if ($compact)
        <flux:button type="button" size="sm" variant="subtle" class="w-full" wire:click="toggleEdit">Cancelar</flux:button>
    @endif

    @unless ($compact)
    <div class="border-t border-line pt-3">
        <h3 class="font-mono text-[10px] font-semibold tracking-wider text-ink-3 uppercase">Atendimentos</h3>
        <ul class="mt-2 space-y-2">
            @forelse ($history as $past)
                <li class="text-xs" wire:key="history-{{ $past->id }}">
                    <p class="font-mono text-ink">{{ $past->protocol }} <span class="font-sans text-ink-3">· {{ $past->opened_at->timezone(config()->string('app.display_timezone'))->format('d/m/Y') }} · {{ $past->sector?->name ?? 'Fila geral' }}</span></p>
                    <p class="text-ink-2">{{ $past->status->label() }}@if ($past->close_outcome) · {{ $past->close_outcome->label() }}@endif</p>
                    @if ($past->close_summary)
                        <p class="text-ink-3">{{ $past->close_summary }}</p>
                    @endif
                </li>
            @empty
                <li class="text-xs text-ink-3">Nenhum atendimento ainda.</li>
            @endforelse
        </ul>
    </div>
    @endunless

    <div class="flex items-start justify-between gap-2 border-t border-line pt-3 text-xs {{ $google['error'] ? 'text-danger' : 'text-ink-3' }}" @if ($google['error']) role="alert" @endif>
        <span>{{ $google['text'] }}</span>
        @if ($google['error'])
            <flux:button size="xs" variant="subtle" icon="arrow-path" wire:click="resync">Reenviar</flux:button>
        @endif
    </div>
</form>
@endif
</div>
