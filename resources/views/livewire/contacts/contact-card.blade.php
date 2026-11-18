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
                'Empresa' => $contact->company,
                'Cargo' => $contact->job_title,
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
@elseif (! $compact && ! $editing)
    {{-- Gaveta de Contatos: ficha de leitura; "Editar contato" troca para o formulário e salvar volta para cá. --}}
    <div data-testid="contact-sheet" class="space-y-4 text-sm">
        <div class="flex items-center gap-3">
            <x-avatar :src="$contact->avatarUrl()" :name="$contact->label()" shape="round" size="lg" decorative />
            <div class="min-w-0">
                <h2 class="text-base font-semibold break-words text-ink">{{ $contact->label() }}</h2>
                <p class="font-mono text-ink-2">{{ $contact->phoneLabel() }}</p>
            </div>
        </div>

        @php($rows = array_filter([
            'E-mail' => $contact->email,
            'Empresa' => $contact->company,
            'Cargo' => $contact->job_title,
            'Outro telefone' => $contact->phone2,
            'Aniversário' => $contact->birthday?->format('d/m/Y'),
            'Endereço' => $contact->address,
        ], fn ($value) => $value !== null && $value !== ''))
        @if ($rows !== [] || $contact->urls || $contactTags->isNotEmpty())
            <dl class="space-y-2.5 rounded-xl border border-line p-4">
                @foreach ($rows as $label => $value)
                    <div class="space-y-0.5">
                        <dt class="text-xs text-ink-3">{{ $label }}</dt>
                        <dd class="text-sm font-medium break-words whitespace-pre-line text-ink">{{ $value }}</dd>
                    </div>
                @endforeach
                @if ($contact->urls)
                    <div class="space-y-0.5">
                        <dt class="text-xs text-ink-3">Sites</dt>
                        @foreach ($contact->urls as $url)
                            <dd class="text-sm font-medium break-all"><a href="{{ $url }}" target="_blank" rel="noopener" class="inline-flex min-h-6 items-center text-accent-content underline-offset-2 hover:underline">{{ $url }}</a></dd>
                        @endforeach
                    </div>
                @endif
                @if ($contactTags->isNotEmpty())
                    <div class="space-y-0.5" data-testid="contact-tags">
                        <dt class="text-xs text-ink-3">Etiquetas</dt>
                        <dd class="flex flex-wrap items-center gap-1.5">
                            @foreach ($contactTags as $tag)
                                <x-tag-chip :tag="$tag" :title="$tag->name" wire:key="contact-tag-{{ $tag->id }}" />
                            @endforeach
                        </dd>
                    </div>
                @endif
            </dl>
        @endif

        <section>
            <h3 class="text-xs font-bold tracking-wider text-ink-3 uppercase">Observações</h3>
            @if ($contact->notes)
                <p class="mt-1 text-sm break-words whitespace-pre-wrap text-ink">{{ $contact->notes }}</p>
            @else
                <p class="mt-1 text-xs text-ink-3">Nenhuma observação.</p>
            @endif
        </section>

        <section>
            <h3 class="text-xs font-bold tracking-wider text-ink-3 uppercase">Atendimentos anteriores</h3>
            <ul class="mt-2 space-y-2">
                @forelse ($history as $past)
                    @php($mine = in_array($past->id, $openable, true))
                    @php($href = $mine ? ($past->status === \App\Enums\TicketStatus::ENCERRADO ? route('historico', ['ticketId' => $past->id]) : route('inbox', ['ticket' => $past->id])) : null)
                    <li wire:key="history-{{ $past->id }}">
                        <x-link-card :href="$href" :title="$past->protocol">
                            <span class="block">{{ $past->opened_at->timezone($tz)->format('d/m/Y') }} · {{ $past->sector?->name ?? 'Fila geral' }}</span>
                            <span class="mt-1 flex flex-wrap items-center gap-1"><x-status-badge :status="$past->status" />@if ($past->close_outcome)<x-status-badge :status="$past->close_outcome" />@endif</span>
                            @if ($past->close_summary)
                                <span class="mt-1 block">{{ $past->close_summary }}</span>
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
        </section>

        <div class="flex items-start justify-between gap-2 text-xs {{ $google['error'] ? 'text-danger' : 'text-ink-3' }}" @if ($google['error']) role="alert" @endif>
            <span>{{ $google['text'] }}</span>
            @if ($google['error'])
                <flux:button size="xs" variant="subtle" icon="arrow-path" wire:click="resync">Reenviar</flux:button>
            @endif
        </div>

        {{-- Rodapé fixo da gaveta, no arranjo do painel da Inbox. `-bottom-8 -mx-8 -mb-8` acertam o `p-8` do `flux:modal` flyout. --}}
        <div data-testid="contact-actions" class="sticky -bottom-8 -mx-8 -mb-8 bg-surface px-8 pt-1 pb-8">
            <flux:separator class="mb-3" />
            <flux:button.group>
                @include('livewire.contacts.chat-button', ['contact' => $contact, 'class' => 'flex-1', 'variant' => 'filled', 'size' => 'xs'])
                @unless ($readonly)
                    <flux:button size="xs" variant="filled" icon="pencil-square" class="flex-1" wire:click="toggleEdit">Editar contato</flux:button>
                @endunless
            </flux:button.group>
        </div>
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

    <flux:button type="submit" size="sm" variant="primary" class="w-full">Salvar contato</flux:button>
    <flux:button type="button" size="sm" variant="subtle" class="w-full" wire:click="toggleEdit">Cancelar</flux:button>

    <div class="flex items-start justify-between gap-2 border-t border-line pt-3 text-xs {{ $google['error'] ? 'text-danger' : 'text-ink-3' }}" @if ($google['error']) role="alert" @endif>
        <span>{{ $google['text'] }}</span>
        @if ($google['error'])
            <flux:button size="xs" variant="subtle" icon="arrow-path" wire:click="resync">Reenviar</flux:button>
        @endif
    </div>
</form>
@endif
</div>
