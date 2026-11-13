@php
    $tz = config('app.display_timezone');
@endphp

<div
    data-open-ticket="{{ $ticketId }}"
    class="grid min-h-0 flex-1 grid-cols-1 bg-surface lg:grid-cols-[340px_minmax(0,1fr)_var(--details-w,280px)]"
    x-data="ticketDetails"
    x-bind:style="details ? '' : '--details-w: 0px'"
    data-client-presence="{{ json_encode($clientPresence, JSON_THROW_ON_ERROR) }}"
    data-presence-ttl="{{ config('presence.state_ttl_seconds') }}"
>
    {{-- Lista --}}
    <section class="flex min-h-0 flex-col border-e border-line {{ $ticket ? 'hidden lg:flex' : '' }}">
        <header class="border-b border-line p-4">
            <div class="flex items-center justify-between gap-2">
                <h1 class="text-base font-semibold text-ink">Fila</h1>
                <flux:button size="sm" variant="primary" icon="plus" x-on:click="$dispatch('start-conversation')">Nova conversa</flux:button>
            </div>

            {{-- Três visões do dia a dia sempre à mão; as duas raras (triagem do bot e histórico recente) ficam no menu "Mais". --}}
            @php
                $more = ['pendentes' => 'Pendentes', 'encerrados' => 'Encerrados'];
                $moreActive = array_key_exists($tab, $more);
            @endphp
            <div class="mt-3 flex items-center gap-2">
                <flux:radio.group wire:model.live="tab" variant="segmented" size="sm" class="min-w-0 flex-1" aria-label="Visão da fila">
                    <flux:radio value="todos" data-testid="tab-todos" class="min-w-0! flex-auto! gap-1! overflow-hidden! px-1! text-xs!">Todos</flux:radio>
                    <flux:radio value="aguardando" data-testid="tab-aguardando" class="min-w-0! flex-auto! gap-1! overflow-hidden! px-1! text-xs!">Aguardando @if (! empty($tabCounts['aguardando']))<span class="font-mono text-ink-2">{{ $tabCounts['aguardando'] }}</span>@endif</flux:radio>
                    <flux:radio value="meus" data-testid="tab-meus" class="min-w-0! flex-auto! gap-1! overflow-hidden! px-1! text-xs!">Meus @if (! empty($tabCounts['meus']))<span class="font-mono text-ink-2">{{ $tabCounts['meus'] }}</span>@endif</flux:radio>
                </flux:radio.group>

                <flux:dropdown position="bottom" align="end">
                    <flux:button size="sm" :variant="$moreActive ? 'filled' : 'subtle'" icon-trailing="chevron-down" class="shrink-0 px-2! text-xs!" data-testid="tab-more" aria-label="{{ $moreActive ? $more[$tab].' (mais visões da fila)' : 'Mais visões da fila' }}">
                        {{ $moreActive ? $more[$tab] : 'Mais' }}
                    </flux:button>

                    <flux:menu>
                        @foreach ($more as $key => $label)
                            <flux:menu.item wire:click="$set('tab', '{{ $key }}')" :icon="$tab === $key ? 'check' : null" :suffix="empty($tabCounts[$key]) ? null : (string) $tabCounts[$key]" data-testid="tab-{{ $key }}">{{ $label }}</flux:menu.item>
                        @endforeach
                    </flux:menu>
                </flux:dropdown>
            </div>

            @if ($contactTags->isNotEmpty() || $ticketTags->isNotEmpty())
                <div class="mt-3">
                    <flux:select wire:model.live="tagId" size="sm" aria-label="Filtrar por etiqueta">
                        <flux:select.option value="">Todas as etiquetas</flux:select.option>
                        @if ($contactTags->isNotEmpty())
                            <optgroup label="Do contato">
                                @foreach ($contactTags as $tag)
                                    <flux:select.option :value="$tag->id">{{ $tag->name }}</flux:select.option>
                                @endforeach
                            </optgroup>
                        @endif
                        @if ($ticketTags->isNotEmpty())
                            <optgroup label="Da conversa">
                                @foreach ($ticketTags as $tag)
                                    <flux:select.option :value="$tag->id">{{ $tag->name }}</flux:select.option>
                                @endforeach
                            </optgroup>
                        @endif
                    </flux:select>
                </div>
            @endif
        </header>

        <div class="relative min-h-0 flex-1 overflow-y-auto">
            @if ($tab === 'encerrados')
                @foreach ($closed as $item)
                    <button
                        type="button"
                        wire:click="select({{ $item->id }})"
                        wire:key="closed-{{ $item->id }}"
                        class="flex w-full items-start gap-3 px-4 py-3 text-start hover:bg-canvas {{ $ticketId === $item->id ? 'bg-canvas' : '' }}"
                    >
                        <x-avatar :src="$item->contact->avatarUrl()" :name="$item->contact->label()" shape="round" size="lg" decorative />

                        <span class="min-w-0 flex-1">
                            <span class="flex items-baseline justify-between gap-2">
                                <span class="truncate text-sm font-semibold text-ink">{{ $item->contact->label() }}</span>
                                <span class="font-mono text-[10px] text-ink-3">{{ $item->closed_at?->timezone($tz)->format('d/m H:i') }}</span>
                            </span>
                            <span class="mt-0.5 block truncate text-xs text-ink-2">{{ $item->protocol }} · {{ $item->close_outcome?->label() ?? $item->closed_reason?->label() }}</span>
                        </span>
                    </button>
                @endforeach

                @if ($closed->isEmpty())
                    <x-empty-state compact icon="archive-box" title="Nenhum ticket encerrado nos últimos 7 dias." />
                @endif

                <p class="p-4 text-center text-xs"><a href="{{ route('historico') }}" wire:navigate class="text-accent-content underline">Busca completa</a></p>
            @else
            @foreach ([['Pendente', $pending, 'text-ink-3'], ['Aguardando', $waiting, 'text-warn'], ['Em atendimento', $active, 'text-accent-content']] as [$heading, $group, $tone])
                @if ($group->isNotEmpty())
                    <p class="px-4 pt-4 pb-1 font-mono text-[10px] font-semibold tracking-wider uppercase {{ $tone }}">
                        {{ $heading }} · {{ $group->count() }}
                    </p>

                    @foreach ($group as $item)
                        <button
                            type="button"
                            wire:click="select({{ $item->id }})"
                            wire:key="ticket-{{ $item->id }}"
                            class="flex w-full items-start gap-3 px-4 py-3 text-start hover:bg-canvas {{ $ticketId === $item->id ? 'bg-canvas' : '' }}"
                        >
                            @php
                                $sla = $item->slaState();
                                $chips = \App\Models\Tag::sortedByName($item->contact->tags)->concat(\App\Models\Tag::sortedByName($item->tags))->values();
                                $maxChips = 6;
                                $chipsShown = $chips->take($maxChips);
                                $chipsRest = $chips->slice($maxChips);
                                $hasMeta = $showChannel;
                                $at = $item->waiting_since ?? $item->last_inbound_at ?? $item->opened_at;
                                $unread = (int) $item->getAttribute('unread');
                            @endphp
                            <span class="relative shrink-0">
                                <x-avatar :src="$item->contact->avatarUrl()" :name="$item->contact->label()" shape="round" size="lg" decorative />
                                <span data-testid="card-channel-icon" class="absolute -end-1 -bottom-1 grid size-4 place-items-center rounded-full bg-surface text-ink-2 ring-2 ring-surface">
                                    <svg viewBox="0 0 24 24" class="size-3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $item->channel->kind->icon() !!}</svg>
                                    <span class="sr-only">{{ $item->channel->kind->label() }}</span>
                                </span>
                                @if ($sla)
                                    <span data-testid="card-sla" title="{{ $sla === 'estourou' ? 'SLA estourado' : 'Perto de estourar' }}" class="absolute -end-0.5 -top-0.5 block size-2.5 rounded-full ring-2 ring-surface {{ $sla === 'estourou' ? 'bg-danger' : 'bg-warn' }}"><span class="sr-only">{{ $sla === 'estourou' ? 'SLA estourado' : 'Perto de estourar' }}</span></span>
                                @endif
                            </span>

                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold text-ink">{{ $item->contact->label() }}</span>
                                @if ($chips->isNotEmpty())
                                    <span class="mt-0.5 flex min-w-0 flex-wrap items-center gap-x-1 gap-y-0.5">
                                        @foreach ($chipsShown as $tag)
                                            <x-tag-chip :tag="$tag" :conversation="$tag->scope === \App\Enums\TagScope::CONVERSA" :data-testid="$tag->scope === \App\Enums\TagScope::CONTATO ? 'card-chip-contact' : 'card-chip-ticket'" />
                                        @endforeach
                                        @if ($chipsRest->isNotEmpty())
                                            <span data-testid="card-tags-more" class="shrink-0 text-[11px] text-ink-3"><span aria-hidden="true" title="{{ $chipsRest->pluck('name')->join(', ') }}">+{{ $chipsRest->count() }}</span><span class="sr-only">mais etiquetas: {{ $chipsRest->pluck('name')->join(', ') }}</span></span>
                                        @endif
                                    </span>
                                @endif
                                <span class="mt-0.5 block min-w-0 truncate text-xs text-ink-2">
                                    <span x-cloak x-show="$store.clientPresence.label({{ $item->id }})" x-text="$store.clientPresence.label({{ $item->id }})" class="font-medium text-accent-content"></span>
                                    <span x-show="! $store.clientPresence.label({{ $item->id }})">{{ \App\Support\WhatsAppText::html((string) $item->preview) }}</span>
                                </span>
                                @if ($hasMeta)
                                    <span data-testid="card-meta" class="mt-1 flex min-w-0 items-center gap-2 overflow-hidden text-[11px] text-ink-3">
                                        @if ($showChannel)
                                            <span class="truncate font-mono text-[10px]">{{ $item->channel->name }} · {{ $item->channel->phoneLabel() ?? 'sem número' }}</span>
                                        @endif
                                    </span>
                                @endif
                            </span>

                            <span class="flex shrink-0 flex-col items-end gap-1">
                                <span data-testid="card-time" x-data="relativeTime('{{ $at->toIso8601String() }}', 'narrow')" x-text="label" class="font-mono text-[10px] text-ink-3">{{ \App\Support\WaitLabel::narrow($at, now()) }}</span>
                                @if ($showSector && $item->sector)
                                    <span data-testid="card-sector" class="max-w-24 truncate rounded bg-canvas px-1.5 py-0.5 text-[10px] text-ink-2">{{ $item->sector->name }}</span>
                                @endif
                                @if ($item->assignee)
                                    <span class="inline-flex max-w-24 items-center gap-1 text-[10px] text-ink-2">
                                        <x-avatar :src="$item->assignee->avatarUrl()" :name="$item->assignee->name" shape="round" size="xs" decorative />
                                        <span class="sr-only">Atendente:</span>
                                        <span data-testid="card-assignee-name" title="{{ $item->assignee->name }}" class="max-w-16 truncate">{{ \Illuminate\Support\Str::before(trim($item->assignee->name), ' ') }}</span>
                                    </span>
                                @endif
                                @if ($unread > 0)
                                    <span data-testid="card-unread" class="min-w-5 rounded-full bg-accent px-1.5 text-center font-mono text-[10px] font-semibold text-accent-foreground"><span aria-hidden="true">{{ $unread > 99 ? '99+' : $unread }}</span><span class="sr-only">mensagens não lidas: {{ $unread }}</span></span>
                                @endif
                            </span>
                        </button>
                    @endforeach
                @endif
            @endforeach

            @if ($pending->isEmpty() && $waiting->isEmpty() && $active->isEmpty())
                <x-empty-state compact icon="inbox" title="Nenhum ticket por aqui." />
            @endif
            @endif
        </div>
    </section>

    {{-- Conversa --}}
    {{-- `@container`: o cabeçalho e o compositor mudam pela largura desta coluna (muda com a sidebar aberta ou fechada e com o painel de detalhes), não pela da janela. --}}
    <section data-testid="conversation-column" class="@container flex min-h-0 flex-col bg-canvas {{ $ticket ? '' : 'hidden lg:flex' }}">
        @if ($ticket)
            @php
                $name = $ticket->contact->label();
            @endphp

            <header class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-line bg-surface px-4 py-2">
                <flux:button variant="subtle" size="sm" icon="arrow-left" class="lg:hidden" wire:click="$set('ticketId', null)" aria-label="Voltar para a lista" />
                <x-avatar :src="$ticket->contact->avatarUrl()" :name="$name" shape="round" size="lg" decorative />

                <div class="min-w-0 flex-1 basis-56">
                    <div data-testid="header-title" class="flex flex-wrap items-center gap-x-2 text-sm font-semibold text-ink">
                        <span class="max-w-full truncate">{{ $name }}</span>
                        <x-status-badge :status="$ticket->status" />
                        @if ($ticket->status !== \App\Enums\TicketStatus::ENCERRADO && $ticket->waiting_since !== null && $ticket->sla_due_at !== null)
                            @php
                                $slaState = $ticket->slaState();
                            @endphp
                            <span data-testid="header-sla" class="text-xs font-medium {{ $slaState === 'estourou' ? 'text-danger' : ($slaState === 'perto' ? 'text-warn' : 'text-ink') }}" role="status">
                                @if ($slaState === 'estourou')
                                    SLA estourado: venceu {{ app(\App\Services\Inbox\BusinessHours::class)->whenLabel($ticket->sla_due_at, now()) }}
                                @else
                                    Responder até {{ app(\App\Services\Inbox\BusinessHours::class)->whenLabel($ticket->sla_due_at, now()) }}
                                @endif
                            </span>
                        @endif
                    </div>
                    <p class="truncate font-mono text-[11px] text-ink-3">
                        {{ $ticket->protocol }} · {{ $ticket->contact->phoneLabel() }} · {{ $ticket->channel->name }}@if ($showChannel) · {{ $ticket->channel->phoneLabel() ?? 'sem número' }}@endif
                    </p>
                    @if ($ticket->status === \App\Enums\TicketStatus::ENCERRADO)
                        <p class="max-h-16 overflow-y-auto text-[11px] break-words text-ink-2">
                            @if ($ticket->close_outcome)
                                <x-status-badge :status="$ticket->close_outcome" class="me-1" />
                            @elseif ($ticket->closed_reason)
                                <x-status-badge tone="neutral" class="me-1">{{ $ticket->closed_reason->label() }}</x-status-badge>
                            @endif
                            @if ($ticket->close_summary){{ $ticket->close_summary }}@endif
                        </p>
                    @endif
                </div>

                {{-- Em janela estreita as ações descem para a linha de baixo, à direita, em vez de espremer o nome e o SLA. --}}
                <div class="ms-auto flex flex-wrap items-center justify-end gap-2">
                    <flux:button size="sm" variant="subtle" icon="information-circle" class="lg:hidden" x-on:click="sheet = true" aria-controls="ticket-details">Detalhes</flux:button>
                    <flux:button size="sm" variant="subtle" icon="information-circle" class="max-lg:hidden" x-on:click="toggle()" aria-controls="ticket-details" x-bind:aria-expanded="details.toString()" x-bind:aria-label="details ? 'Recolher detalhes' : 'Mostrar detalhes'" />

                    @if ($canClaim)
                        <flux:button variant="primary" size="sm" icon="check" wire:click="claim" wire:loading.attr="disabled">Assumir</flux:button>
                    @endif

                    @if ($canTakeover)
                        <flux:button size="sm" icon="arrow-uturn-left" wire:click="takeover({{ $ticket->assigned_user_id }})" wire:confirm="Tomar este ticket de {{ $ticket->assignee?->name }}?">Tomar</flux:button>
                    @endif

                    @if ($canTransfer)
                        <flux:button size="sm" icon="arrows-right-left" class="@max-2xl:w-8 @max-2xl:px-0" wire:click="openPanel('transfer')" aria-label="Transferir" tooltip="Transferir"><span class="hidden @2xl:inline">Transferir</span></flux:button>
                    @endif

                    @if ($canReassign)
                        <flux:button size="sm" icon="user-plus" class="@max-2xl:w-8 @max-2xl:px-0" wire:click="openPanel('reassign')" aria-label="Reatribuir" tooltip="Reatribuir"><span class="hidden @2xl:inline">Reatribuir</span></flux:button>
                    @endif

                    @if ($canClose)
                        <flux:button size="sm" wire:click="openPanel('close')">Encerrar</flux:button>
                    @endif
                </div>
            </header>

            @if ($panel === 'transfer')
                <form wire:submit="transfer" class="flex flex-wrap items-end gap-3 border-b border-line bg-surface px-4 py-3">
                    <div class="w-48">
                        <flux:select wire:model="transferSectorId" label="Transferir para o setor">
                            <flux:select.option value="">Escolha o setor</flux:select.option>
                            @foreach ($sectors as $sector)
                                <flux:select.option :value="$sector->id">{{ $sector->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                    <div class="min-w-48 flex-1">
                        <flux:input wire:model="transferNote" label="Nota (opcional, só para a equipe)" maxlength="500" />
                        @error('transferNote')
                            <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                        @enderror
                    </div>
                    <flux:button type="submit" variant="primary" size="sm">Transferir</flux:button>
                    <flux:button type="button" size="sm" variant="subtle" wire:click="closePanel">Cancelar</flux:button>
                </form>
            @elseif ($panel === 'close')
                <form wire:submit="close" class="space-y-3 border-b border-line bg-surface px-4 py-3">
                    <p class="text-xs text-ink-3">Encerrar {{ $ticket->protocol }}. A próxima mensagem do cliente abre um ticket novo.</p>
                    <div class="w-56">
                        <flux:select wire:model="closeOutcome" label="Motivo do encerramento">
                            <flux:select.option value="">Escolha o motivo</flux:select.option>
                            @foreach (\App\Enums\CloseOutcome::cases() as $outcome)
                                <flux:select.option :value="$outcome->value">{{ $outcome->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                    <div>
                        <flux:textarea wire:model="closeSummary" rows="3" label="Resumo do atendimento" placeholder="O que foi pedido e o que ficou combinado" maxlength="500" />
                        <p class="mt-1 text-xs text-ink-3">Obrigatório. Aparece no histórico do contato e na auditoria.</p>
                        @error('closeSummary')
                            <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                        @enderror
                    </div>
                    <div class="flex gap-2">
                        <flux:button type="submit" variant="primary" size="sm">Encerrar ticket</flux:button>
                        <flux:button type="button" size="sm" variant="subtle" wire:click="closePanel">Cancelar</flux:button>
                    </div>
                </form>
            @elseif ($panel === 'reassign')
                <form wire:submit="reassign" class="flex flex-wrap items-end gap-3 border-b border-line bg-surface px-4 py-3">
                    <div class="w-64">
                        <flux:select wire:model="reassignUserId" label="Reatribuir para">
                            <flux:select.option value="">Escolha a pessoa</flux:select.option>
                            @foreach ($candidates as $candidate)
                                <flux:select.option :value="$candidate->id">{{ $candidate->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                    <flux:button type="submit" variant="primary" size="sm">Reatribuir</flux:button>
                    <flux:button type="button" size="sm" variant="subtle" wire:click="closePanel">Cancelar</flux:button>
                </form>
            @endif

            @if ($ticket->status === \App\Enums\TicketStatus::PENDENTE)
                <div class="border-b border-line bg-surface px-4 py-2 text-xs text-ink-2" role="status">Em triagem automática: o bot ainda conduz esta conversa. Você pode ler e transferir.</div>
            @endif

            @if ($previous)
                <div class="border-b border-line px-4 py-1.5 text-xs">
                    <flux:button type="button" size="xs" variant="subtle" icon="arrow-left" wire:click="goBack">Voltar ao ticket anterior ({{ $previous->protocol ?? '#'.$previous->id }})</flux:button>
                </div>
            @endif

            @if ($notice)
                <div class="border-b border-warn-line bg-warn-soft px-4 py-2 text-sm text-warn" role="alert">{{ $notice }}</div>
            @endif

            <div class="flex min-h-0 flex-1 flex-col-reverse overflow-y-auto px-4 py-4">
                {{-- Primeiro filho de um `flex-col-reverse` fica no fim da conversa: o "digitando…" do cliente, como uma bolha dele. --}}
                <div x-cloak x-show="$store.clientPresence.label({{ $ticket->id }})" class="flex justify-start pt-2" role="status" aria-live="polite">
                    <div class="animate-pulse rounded-xl bg-surface px-3 py-2 text-sm font-medium text-accent-content" x-text="$store.clientPresence.label({{ $ticket->id }})"></div>
                </div>
                <livewire:inbox.transcript :ticket-id="$ticket->id" :key="'transcript-'.$ticket->id" />
            </div>

            {{-- Modo Responder/Nota privada e rascunhos por modo: ver resources/js/composer-mode.js --}}
            <footer
                wire:key="footer-{{ $ticket->id }}"
                @if ($canReply)
                    x-data="composerMode($wire, {{ $ticket->id }}, {{ auth()->id() }})"
                    x-on:keydown.alt.p.window.prevent="toggle()"
                    x-on:note-from-message.window="addToNote($event.detail)"
                    x-on:emoji-insert="insert($event.detail)"
                    x-on:composer-focus="focusField()"
                    x-bind:class="mode === 'note' ? 'border-warn-line bg-warn-soft' : 'border-line bg-surface'"
                @endif
                class="border-t p-3 {{ $canReply ? '' : 'border-line bg-surface' }}"
            >
                @if ($replyTo)
                    <div @if ($canReply) x-show="mode === 'reply'" @endif class="mb-2 flex items-start gap-2 rounded-md border-l-4 border-accent bg-canvas px-3 py-2 text-xs" role="status">
                        <div class="min-w-0 flex-1">
                            @include('livewire.inbox.quote-preview', ['quote' => $replyTo, 'heading' => 'Respondendo a '.$replyTo['author']])
                        </div>
                        <flux:button type="button" size="xs" variant="ghost" icon="x-mark" wire:click="cancelReply" aria-label="Cancelar resposta" />
                    </div>
                @endif

                @if ($canReply && $ticket->channel->status !== \App\Enums\ChannelStatus::WORKING)
                    <p class="mb-1.5 truncate rounded-md bg-warn-soft px-3 py-1.5 text-xs text-warn" role="status" title="O WhatsApp deste canal está desconectado. As mensagens ficam aguardando e saem sozinhas quando a conexão voltar (por até 24 h).">O WhatsApp deste canal está desconectado. As mensagens ficam aguardando e saem sozinhas quando a conexão voltar (por até 24 h).</p>
                @endif

                @if ($canReply)

                    <div
                        wire:key="composer-{{ $ticket->id }}"
                        x-data="attachTray($wire, {{ $ticket->id }})"
                        x-on:dragover.prevent="dragging = true"
                        x-on:dragleave.prevent="dragging = false"
                        x-on:drop.prevent="drop($event)"
                        x-on:paste="paste($event)"
                        x-on:voice-phase.window="voiceBusy = $event.detail !== 'idle'"
                        x-bind:class="dragging ? 'rounded-md ring-2 ring-accent' : ''"
                    >
                        @if ($attachments !== [])
                            <ul x-show="!voiceBusy" class="mb-2 flex flex-wrap gap-2" aria-label="Arquivos para enviar">
                                @foreach ($attachments as $index => $file)
                                    <li wire:key="attachment-{{ $index }}-{{ $file->getFilename() }}" class="flex items-center gap-2 rounded-md border border-line bg-canvas px-2 py-1 text-xs">
                                        <flux:icon.paper-clip class="size-3.5 text-ink-3" />
                                        <span class="max-w-40 truncate text-ink">{{ $file->getClientOriginalName() }}</span>
                                        <span class="font-mono text-ink-3">{{ \Illuminate\Support\Number::fileSize($file->getSize(), 1) }}</span>
                                        <flux:button type="button" size="xs" variant="ghost" icon="x-mark" wire:click="removeAttachment({{ $index }})" aria-label="Remover {{ $file->getClientOriginalName() }}" />
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <p x-show="uploading > 0" x-cloak class="mb-2 text-xs text-ink-3" role="status">Enviando arquivo…</p>
                        <p x-show="notice" x-cloak x-text="notice" class="mb-2 text-xs text-danger" role="alert"></p>
                        @error('attachments')
                            <p class="mb-2 text-xs text-danger" role="alert">{{ $message }}</p>
                        @enderror

                        {{-- Gravador de voz: o estado vive no Alpine (wire:ignore); ver resources/js/voice-recorder.js --}}
                        <div wire:ignore x-data="voiceRecorder($wire, {{ $ticket->id }})" x-on:voice-start.window="start()">
                            <p x-show="error" x-cloak x-text="error" class="mb-2 text-xs text-danger" role="alert"></p>

                            <div x-show="phase === 'recording'" x-cloak class="flex items-center gap-3 rounded-md border border-danger/40 bg-danger/10 px-3 py-2">
                                <span class="size-2 animate-pulse rounded-full bg-danger" aria-hidden="true"></span>
                                <span class="font-mono text-sm text-ink" x-text="clock" role="timer"></span>
                                <span class="flex-1 text-xs text-ink-2">Gravando… o cliente vê "gravando áudio"</span>
                                <flux:button type="button" size="xs" variant="subtle" x-on:click="discard()">Descartar</flux:button>
                                <flux:button type="button" size="xs" variant="primary" x-on:click="stop()">Parar</flux:button>
                            </div>

                            <div x-show="phase === 'preview'" x-cloak class="flex items-center gap-2 rounded-md border border-line bg-canvas px-3 py-2">
                                <audio x-bind:src="url" controls class="h-9 min-w-0 flex-1"></audio>
                                <flux:button type="button" size="xs" variant="subtle" x-on:click="rerecord()" x-bind:disabled="sending">Regravar</flux:button>
                                <flux:button type="button" size="xs" variant="subtle" x-on:click="discard()" x-bind:disabled="sending">Descartar</flux:button>
                                <flux:button type="button" size="xs" variant="primary" x-on:click="send()" x-bind:disabled="sending">Enviar</flux:button>
                            </div>
                        </div>

                        @error('voice')
                            <p class="mb-2 text-xs text-danger" role="alert">{{ $message }}</p>
                        @enderror

                        <form data-testid="composer-form" x-on:submit.prevent="uploading === 0 && submit()" x-on:input="onInput($event)" class="flex flex-col gap-2" x-show="!voiceBusy">
                            <input
                                type="file"
                                multiple
                                class="hidden"
                                x-ref="picker"
                                aria-label="Escolher arquivos"
                                accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.csv,.mp3,.ogg,.m4a,.mp4"
                                x-on:change="pickFiles($event)"
                            >
                            {{-- Linha 1: abas Responder/Nota. Linha 2: ferramentas, campo e mic (vira Enviar com texto ou arquivo). --}}
                            <div data-testid="composer-modes">
                                <div class="inline-flex rounded-md border border-line bg-canvas p-0.5 text-xs font-medium" role="tablist" aria-label="Tipo de envio">
                                    <button type="button" role="tab" data-testid="mode-reply" x-on:click="toggle('reply')" x-bind:aria-selected="mode === 'reply'" x-bind:class="mode === 'reply' ? 'bg-surface text-ink shadow-sm' : 'text-ink-3 hover:text-ink'" class="rounded px-3 py-1">Responder</button>
                                    <button type="button" role="tab" data-testid="mode-note" x-on:click="toggle('note')" x-bind:aria-selected="mode === 'note'" x-bind:class="mode === 'note' ? 'bg-warn-soft text-warn shadow-sm' : 'text-ink-3 hover:text-ink'" class="flex items-center gap-1 rounded px-3 py-1"><flux:icon.lock-closed class="size-3" />Nota privada</button>
                                </div>
                            </div>
                            {{-- Coluna larga (@md): uma linha só. Coluna estreita (1280 px com o painel de contato aberto): o campo ocupa a linha de cima e as ferramentas ficam embaixo. --}}
                            <div data-testid="composer-tools" class="flex flex-wrap items-end gap-2">
                            {{-- Seletor de emoji: o elemento vive no Alpine (wire:ignore); ver resources/js/emoji-picker.js --}}
                            <div class="relative" wire:ignore x-data="emojiPicker" x-on:keydown.escape="close(true)" x-on:click.outside="close()">
                                <flux:button type="button" variant="subtle" icon="face-smile" aria-label="Emoji" title="Emoji" aria-haspopup="dialog" x-bind:aria-expanded="open.toString()" x-on:click="toggle()" />
                                <div x-ref="panel" x-show="open" x-cloak role="dialog" aria-label="Seletor de emoji" class="absolute bottom-full start-0 z-20 mb-2 overflow-hidden rounded-lg border border-line shadow-lg"></div>
                            </div>
                            <flux:button type="button" variant="subtle" icon="paper-clip" aria-label="Anexar arquivo" title="Anexar arquivo" x-show="mode === 'reply'" x-on:click="$refs.picker.click()" />

                            <div class="relative" x-on:keydown.escape.window="closeQuickReplies()" x-on:click.outside="$wire.showQuickReplies && $wire.set('showQuickReplies', false)">
                                {{-- Coluna muito estreita (celular): o gatilho vira "+", e a lista é a mesma. --}}
                                <flux:button type="button" variant="subtle" icon="bolt" class="@max-sm:hidden" aria-label="Respostas rápidas" title="Respostas rápidas" aria-haspopup="true" aria-expanded="{{ $showQuickReplies ? 'true' : 'false' }}" wire:click="toggleQuickReplies" />
                                <flux:button type="button" variant="subtle" icon="plus" class="@sm:hidden" aria-label="Mais ações do compositor" title="Mais ações: respostas rápidas" aria-haspopup="true" aria-expanded="{{ $showQuickReplies ? 'true' : 'false' }}" wire:click="toggleQuickReplies" />
                                @if ($showQuickReplies)
                                    <div data-testid="quick-replies-popover" class="absolute bottom-full start-0 z-20 mb-2 w-72 max-w-[80vw] overflow-hidden rounded-lg border border-line bg-surface text-sm shadow-lg">
                                        <p class="border-b border-line px-3 py-2 text-xs font-semibold text-ink-2">Respostas rápidas</p>
                                        @forelse ($this->allQuickReplies() as $reply)
                                            <button type="button" data-body="{{ $reply->body }}" class="flex w-full gap-2 px-3 py-2 text-start hover:bg-surface-2 focus-visible:bg-surface-2" wire:key="qr-all-{{ $reply->id }}" x-on:click="insertQuickReply($el.dataset.body)">
                                                <code class="shrink-0 font-semibold">/{{ $reply->shortcut }}</code>
                                                <span class="truncate text-ink-2">{{ $reply->body }}</span>
                                            </button>
                                        @empty
                                            <p class="px-3 py-3 text-ink-3">Nenhuma resposta rápida. Cadastre em Configuração.</p>
                                        @endforelse
                                    </div>
                                @endif
                            </div>

                            <div class="min-w-0 flex-1 order-first basis-full @md:order-none @md:basis-0" x-data="typingPresence($wire, {{ $ticket->id }})" x-on:composer-mode.window="$event.detail === 'note' && stop()">
                                @if (str_starts_with($draft, '/') && ($suggestions = $this->quickReplies())->isNotEmpty())
                                    <ul class="mb-2 divide-y divide-line overflow-hidden rounded-lg border border-line bg-surface text-sm" role="listbox" aria-label="Respostas rápidas">
                                        @foreach ($suggestions as $suggestion)
                                            <li wire:key="qr-{{ $suggestion->id }}">
                                                <button type="button" class="flex w-full gap-2 px-3 py-2 text-start hover:bg-surface-2" wire:click="useQuickReply({{ $suggestion->id }})">
                                                    <code class="shrink-0 font-semibold">/{{ $suggestion->shortcut }}</code>
                                                    <span class="truncate text-ink-2">{{ $suggestion->body }}</span>
                                                </button>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                                <flux:textarea wire:model="draft" rows="auto" placeholder="Escreva uma resposta" x-bind:placeholder="mode === 'note' ? 'Nota privada: só a equipe vê' : 'Escreva uma resposta'" aria-label="Mensagem" x-on:input="$event.isTrusted && mode === 'reply' && input($event.target.value)" x-on:blur="stop()" x-on:input.debounce.250ms="$event.target.value.startsWith('/') && $wire.$set('draft', $event.target.value)" x-on:keydown.enter="enter($event, uploading)" />
                                @error('draft')
                                    <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                                @enderror
                            </div>
                            @if ($attachments === [])
                                <flux:button type="button" variant="subtle" icon="microphone" class="@max-md:ms-auto" aria-label="Gravar áudio" title="Gravar áudio" x-show="mode === 'reply' && !filled" x-on:click="$dispatch('voice-start')" />
                                <flux:button type="submit" variant="primary" icon="paper-airplane" class="@max-md:ms-auto" aria-label="Enviar" title="Enviar" x-show="mode === 'reply' && filled" x-bind:disabled="uploading > 0" />
                            @else
                                <flux:button type="submit" variant="primary" icon="paper-airplane" class="@max-md:ms-auto" aria-label="Enviar" title="Enviar" x-show="mode === 'reply'" x-bind:disabled="uploading > 0" />
                            @endif
                            <button type="submit" x-show="mode === 'note'" x-cloak class="@max-md:ms-auto rounded-md bg-warn px-3 py-2 text-sm font-medium text-warn-foreground hover:brightness-95 disabled:opacity-50" x-bind:disabled="uploading > 0">Salvar nota</button>
                            </div>
                            <p x-show="mode === 'note'" x-cloak class="basis-full text-xs text-warn" role="status">Só a equipe vê. O cliente não recebe esta nota. (Alt+P alterna)</p>
                        </form>
                    </div>
                @elseif ($ticket->status === \App\Enums\TicketStatus::ENCERRADO)
                    <p class="text-center text-sm text-ink-3">Ticket encerrado. Somente leitura.</p>
                @elseif (! $ticket->channel->active)
                    <p class="text-center text-sm text-ink-3" role="status">Canal desativado. Reative para responder.</p>
                @elseif ($ticket->status === \App\Enums\TicketStatus::PENDENTE)
                    <p class="text-center text-sm text-ink-3" role="status">Em triagem automática. Transfira para um setor para um atendente responder.</p>
                @elseif ($ticket->assigned_user_id === null)
                    <p class="text-center text-sm text-ink-3">Assuma o ticket para responder.</p>
                @else
                    <p class="text-center text-sm text-ink-3">Só o responsável responde este ticket.</p>
                @endif
            </footer>
        @else
            <div class="grid flex-1 place-items-center text-sm text-ink-3">Selecione um ticket.</div>
        @endif
    </section>

    <livewire:inbox.new-conversation />

    {{-- Detalhes --}}
    @if ($ticket)
        <x-ticket-details :ticket="$ticket" :tz="$tz" :show-channel="$showChannel" />
    @endif
</div>
