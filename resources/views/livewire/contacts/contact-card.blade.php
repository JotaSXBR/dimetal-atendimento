{{-- Compacto (Inbox): a raiz e o resumo não geram caixa (`contents`), para o rodapé `sticky` grudar no fim do `aside` de rolagem. --}}
<div @class(['contents' => $compact && ! $editing]) x-data="focusTagPicker" data-focus-target="[data-testid=contact-tags] input[role=combobox]" x-on:tag-removed.self="focus">
@php($tz = config()->string('app.display_timezone'))
@if ($compact && ! $editing)
    <div class="contents text-sm">
        {{-- O telefone já está no cabeçalho da conversa; só o Histórico (somente leitura, sem esse cabeçalho) o mostra aqui. --}}
        <x-disclosure title="Contato" testid="details-contact">
        <dl class="space-y-2.5">
            @foreach (array_filter([
                'Nome' => $contact->label(),
                'Empresa' => implode(' · ', array_filter([$contact->company, $contact->job_title])),
                'Telefone' => $readonly ? $contact->phoneLabel() : null,
                'E-mail' => $contact->email,
            ], fn ($value) => $value !== null && $value !== '') as $label => $value)
                <div class="space-y-0.5">
                    <dt class="text-xs text-ink-3">{{ $label }}</dt>
                    <dd @class(['text-sm font-medium break-words text-ink', 'font-mono' => $label === 'Telefone'])>{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
        </x-disclosure>

        {{-- Um bloco só: a linha "Conversa" (as etiquetas do ticket) e a linha "Contato". --}}
        <x-disclosure title="Etiquetas" testid="details-tags">
        <div class="space-y-3">
            @if ($currentTicketId !== null)
                <livewire:inbox.ticket-tags :ticket-id="$currentTicketId" :readonly="$readonly" :key="'ticket-tags-'.$currentTicketId" />
            @endif
            @include('livewire.contacts.tags', ['rowLabel' => 'Contato'])
        </div>
        </x-disclosure>

        <x-disclosure :title="'Observações ('.($contact->notes ? 1 : 0).')'" :open="false" testid="details-notes">
            @if ($contact->notes)
                <p class="text-xs break-words whitespace-pre-wrap text-ink">{{ $contact->notes }}</p>
            @else
                <p class="text-xs text-ink-3">Nenhuma observação.</p>
            @endif
        </x-disclosure>

        <x-disclosure :title="'Atendimentos anteriores ('.$historyTotal.')'" :open="false" testid="details-history">
            <ul class="space-y-2">
                @forelse ($history as $past)
                    @php($mine = in_array($past->id, $openable, true))
                    @php($closed = $past->status === \App\Enums\TicketStatus::ENCERRADO)
                    @php($otherNumber = $currentChannelId !== null && $past->channel_id !== $currentChannelId)
                    @php($number = $past->channel?->phoneLabel() ?? $past->channel?->name ?? '')
                    {{-- Só a Fila tem `select`; no Histórico (somente leitura) o pai é outro componente, então o aberto vai à Inbox por link. --}}
                    @php($href = $mine && $closed ? route('historico', ['ticketId' => $past->id]) : ($mine && $readonly ? route('inbox', ['ticket' => $past->id]) : null))
                    @php($select = $mine && ! $closed && ! $readonly ? '$parent.select('.$past->id.')' : null)
                    <li wire:key="history-{{ $past->id }}">
                        <x-link-card :href="$href" :title="$past->protocol" :wire:click="$select">
                            <span class="block">{{ $past->opened_at->timezone($tz)->format('d/m/Y') }}@if ($mine) · {{ $past->sector?->name ?? 'Fila geral' }}@endif</span>
                            @if (! $closed && $otherNumber)
                                <span class="block font-semibold text-warn">também tem ticket aberto no número {{ $number }} ({{ $past->status->label() }})</span>
                            @else
                                <span class="mt-1 flex flex-wrap items-center gap-1"><x-status-badge :status="$past->status" />@if ($past->close_outcome)<x-status-badge :status="$past->close_outcome" />@endif @if ($otherNumber)<span>· número {{ $number }}</span>@endif</span>
                            @endif
                        </x-link-card>
                    </li>
                @empty
                    <li class="text-xs text-ink-3">Nenhum atendimento ainda.</li>
                @endforelse
            </ul>
            @if ($hasMore)
                <x-history-link :contact="$contact" />
            @endif
        </x-disclosure>

        @if ($google['error'])
            <div class="flex items-start justify-between gap-2 text-xs text-danger" role="alert">
                <span>{{ $google['text'] }}</span>
                <flux:button size="xs" variant="subtle" icon="arrow-path" wire:click="resync">Reenviar</flux:button>
            </div>
        @endif

        @if ($canChat || ! $readonly)
            {{-- Rodapé fixo do painel: separador e grupo de botões do Flux (as variantes `filled`/`outline` se colam). `-bottom-4 -mx-4 -mb-4` acertam o `p-4` do
                 `aside` (ticket-details); `mt-auto` o leva ao fim do painel quando há pouco conteúdo. --}}
            <div data-testid="contact-actions" class="sticky -bottom-4 -mx-4 -mb-4 mt-auto bg-surface px-4 pt-1 pb-4">
                <flux:separator class="mb-3" />
                <flux:button.group>
                    @if ($canChat)
                        @include('livewire.contacts.chat-button', ['contact' => $contact, 'class' => 'flex-1', 'variant' => 'filled', 'size' => 'xs'])
                    @endif
                    @unless ($readonly)
                        <flux:button size="xs" variant="filled" icon="pencil-square" class="flex-1" wire:click="toggleEdit">Editar contato</flux:button>
                    @endunless
                </flux:button.group>
            </div>
        @endif
    </div>
@else
<form wire:submit="save" class="space-y-3 text-sm">
    <div>
        <h2 class="text-xs font-semibold text-ink-3">Contato</h2>
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

    @include('livewire.contacts.tags')

    @unless ($compact)
        @include('livewire.contacts.chat-button', ['contact' => $contact, 'class' => 'w-full'])
    @endunless
    <flux:button type="submit" size="sm" variant="primary" class="w-full">Salvar contato</flux:button>
    @if ($compact)
        <flux:button type="button" size="sm" variant="subtle" class="w-full" wire:click="toggleEdit">Cancelar</flux:button>
    @endif

    @unless ($compact)
    <div class="border-t border-line pt-3">
        <h3 class="text-xs font-semibold text-ink-3">Atendimentos</h3>
        <ul class="mt-2 space-y-2">
            @forelse ($history as $past)
                <li class="text-xs" wire:key="history-{{ $past->id }}">
                    <p class="font-mono text-ink">
                        @if (in_array($past->id, $openable, true))
                            <a href="{{ $past->status === \App\Enums\TicketStatus::ENCERRADO ? route('historico', ['ticketId' => $past->id]) : route('inbox', ['ticket' => $past->id]) }}" wire:navigate class="inline-flex min-h-6 items-center text-accent-content underline-offset-2 hover:underline">{{ $past->protocol }}</a>
                        @else
                            {{ $past->protocol }}
                        @endif
                        <span class="font-sans text-ink-3">· {{ $past->opened_at->timezone(config()->string('app.display_timezone'))->format('d/m/Y') }} · {{ $past->sector?->name ?? 'Fila geral' }}</span></p>
                    <p class="flex flex-wrap items-center gap-1 text-ink-2"><x-status-badge :status="$past->status" />@if ($past->close_outcome)<x-status-badge :status="$past->close_outcome" />@endif</p>
                    @if ($past->close_summary)
                        <p class="text-ink-3">{{ $past->close_summary }}</p>
                    @endif
                </li>
            @empty
                <li class="text-xs text-ink-3">Nenhum atendimento ainda.</li>
            @endforelse
        </ul>
        @if ($hasMore)
            <x-history-link :contact="$contact" />
        @endif
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
