@php
    $tz = config('app.display_timezone');
    $statusColor = fn (\App\Enums\TicketStatus $s) => match ($s) {
        \App\Enums\TicketStatus::AGUARDANDO => 'amber',
        \App\Enums\TicketStatus::EM_ATENDIMENTO => 'green',
        default => 'zinc',
    };
    $tabs = ['todos' => 'Todos', 'aguardando' => 'Aguardando', 'meus' => 'Meus', 'encerrados' => 'Encerrados'];
@endphp

<div
    data-open-ticket="{{ $ticketId }}"
    class="grid min-h-0 flex-1 grid-cols-1 bg-surface lg:grid-cols-[340px_minmax(0,1fr)_var(--details-w,280px)]"
    x-data="{ details: localStorage.getItem('ticketDetailsOpen') !== '0', sheet: false, toggle() { this.details = ! this.details; localStorage.setItem('ticketDetailsOpen', this.details ? '1' : '0') } }"
    x-bind:style="details ? '' : '--details-w: 0px'"
>
    {{-- Lista --}}
    <section class="flex min-h-0 flex-col border-e border-line {{ $ticket ? 'hidden lg:flex' : '' }}">
        <header class="border-b border-line p-4">
            <h1 class="text-base font-semibold text-ink">Fila</h1>

            <div class="mt-3 flex gap-1" role="tablist">
                @foreach ($tabs as $key => $label)
                    <button
                        type="button"
                        role="tab"
                        aria-selected="{{ $tab === $key ? 'true' : 'false' }}"
                        wire:click="$set('tab', '{{ $key }}')"
                        class="rounded-full px-3 py-1 text-xs font-medium {{ $tab === $key ? 'bg-ink text-white' : 'text-ink-2 hover:bg-canvas' }}"
                    >
                        {{ $label }}@if (! empty($tabCounts[$key])) <span class="font-mono opacity-70">{{ $tabCounts[$key] }}</span>@endif
                    </button>
                @endforeach
            </div>
        </header>

        <div class="min-h-0 flex-1 overflow-y-auto">
            @if ($tab === 'encerrados')
                @foreach ($closed as $item)
                    <button
                        type="button"
                        wire:click="select({{ $item->id }})"
                        wire:key="closed-{{ $item->id }}"
                        class="flex w-full items-start gap-3 px-4 py-3 text-start hover:bg-canvas {{ $ticketId === $item->id ? 'bg-canvas' : '' }}"
                    >
                        <flux:avatar :name="$item->contact->label()" size="sm" />

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
                    <p class="p-6 text-center text-sm text-ink-3">Nenhum ticket encerrado nos últimos 7 dias.</p>
                @endif

                <p class="p-4 text-center text-xs"><a href="{{ route('historico') }}" wire:navigate class="text-accent underline">Busca completa</a></p>
            @else
            @foreach ([['Aguardando', $waiting, 'text-warn'], ['Em atendimento', $active, 'text-accent']] as [$heading, $group, $tone])
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
                            <flux:avatar :name="$item->contact->label()" size="sm" />

                            <span class="min-w-0 flex-1">
                                <span class="flex items-baseline justify-between gap-2">
                                    <span class="truncate text-sm font-semibold text-ink">{{ $item->contact->label() }}</span>
                                    <span class="font-mono text-[10px] text-ink-3">
                                        {{ ($item->last_inbound_at ?? $item->opened_at)->timezone($tz)->format('H:i') }}
                                    </span>
                                </span>
                                <span class="mt-0.5 block truncate text-xs text-ink-2">{{ $item->preview }}</span>
                                @if ($showChannel)
                                    <span class="mt-0.5 block truncate font-mono text-[10px] text-ink-3">{{ $item->channel->name }} · {{ $item->channel->phoneLabel() ?? 'sem número' }}</span>
                                @endif
                                @if ($state = $item->slaState())
                                    <span class="mt-1 inline-block rounded-full px-2 py-0.5 text-[10px] font-medium {{ $state === 'estourou' ? 'bg-danger/10 text-danger' : 'bg-warn/10 text-warn' }}">{{ $state === 'estourou' ? 'SLA estourado' : 'Perto de estourar' }}</span>
                                @elseif ($item->waiting_since !== null && $item->sla_due_at !== null)
                                    <span class="mt-1 block text-[10px] text-ink-3">Responder até {{ app(\App\Services\Inbox\BusinessHours::class)->whenLabel($item->sla_due_at, now()) }}</span>
                                @endif
                            </span>
                        </button>
                    @endforeach
                @endif
            @endforeach

            @if ($waiting->isEmpty() && $active->isEmpty())
                <p class="p-6 text-center text-sm text-ink-3">Nenhum ticket por aqui.</p>
            @endif
            @endif
        </div>
    </section>

    {{-- Conversa --}}
    <section class="flex min-h-0 flex-col bg-canvas {{ $ticket ? '' : 'hidden lg:flex' }}">
        @if ($ticket)
            @php
                $name = $ticket->contact->label();
            @endphp

            <header class="flex items-center gap-3 border-b border-line bg-surface px-4 py-3">
                <flux:button variant="subtle" size="sm" icon="arrow-left" class="lg:hidden" wire:click="$set('ticketId', null)" aria-label="Voltar para a lista" />
                <flux:avatar :name="$name" size="sm" />

                <div class="min-w-0 flex-1">
                    <p class="flex items-center gap-2 text-sm font-semibold text-ink">
                        <span class="truncate">{{ $name }}</span>
                        <flux:badge size="sm" :color="$statusColor($ticket->status)">{{ $ticket->status->label() }}</flux:badge>
                    </p>
                    <p class="truncate font-mono text-[11px] text-ink-3">
                        {{ $ticket->protocol }} · {{ $ticket->contact->phoneLabel() }} · {{ $ticket->channel->name }}@if ($showChannel) · {{ $ticket->channel->phoneLabel() ?? 'sem número' }}@endif
                    </p>
                    @if ($ticket->status === \App\Enums\TicketStatus::ENCERRADO)
                        <p class="max-h-16 overflow-y-auto text-[11px] break-words text-ink-2">
                            {{ $ticket->close_outcome?->label() ?? $ticket->closed_reason?->label() }}@if ($ticket->close_summary) · {{ $ticket->close_summary }}@endif
                        </p>
                    @endif
                    @if ($ticket->status !== \App\Enums\TicketStatus::ENCERRADO && $ticket->waiting_since !== null && $ticket->sla_due_at !== null)
                        @php($slaState = $ticket->slaState())
                        <p class="text-xs font-medium {{ $slaState === 'estourou' ? 'text-danger' : ($slaState === 'perto' ? 'text-warn' : 'text-ink') }}" role="status">
                            @if ($slaState === 'estourou')
                                SLA estourado: venceu {{ app(\App\Services\Inbox\BusinessHours::class)->whenLabel($ticket->sla_due_at, now()) }}
                            @else
                                Responder até {{ app(\App\Services\Inbox\BusinessHours::class)->whenLabel($ticket->sla_due_at, now()) }}
                            @endif
                        </p>
                    @endif
                </div>

                <flux:button size="sm" variant="subtle" icon="information-circle" class="lg:hidden" x-on:click="sheet = true" aria-controls="ticket-details">Detalhes</flux:button>
                <flux:button size="sm" variant="subtle" icon="information-circle" class="max-lg:hidden" x-on:click="toggle()" aria-controls="ticket-details" x-bind:aria-expanded="details.toString()" x-bind:aria-label="details ? 'Recolher detalhes' : 'Mostrar detalhes'" />

                @if ($canClaim)
                    <flux:button variant="primary" size="sm" icon="check" wire:click="claim" wire:loading.attr="disabled">Assumir</flux:button>
                @endif

                @if ($canTakeover)
                    <flux:button size="sm" icon="arrow-uturn-left" wire:click="takeover({{ $ticket->assigned_user_id }})" wire:confirm="Tomar este ticket de {{ $ticket->assignee?->name }}?">Tomar</flux:button>
                @endif

                @if ($canTransfer)
                    <flux:button size="sm" icon="arrows-right-left" wire:click="openPanel('transfer')">Transferir</flux:button>
                @endif

                @if ($canReassign)
                    <flux:button size="sm" icon="user-plus" wire:click="openPanel('reassign')">Reatribuir</flux:button>
                @endif

                @if ($canClose)
                    <flux:button size="sm" wire:click="openPanel('close')">Encerrar</flux:button>
                @endif
            </header>

            @if ($panel === 'transfer')
                <form wire:submit="transfer" class="flex flex-wrap items-end gap-3 border-b border-line bg-surface px-4 py-3">
                    <div class="w-48">
                        <flux:select wire:model="transferSectorId" label="Transferir para o setor" placeholder="Escolha o setor">
                            @foreach ($sectors as $sector)
                                <flux:select.option :value="$sector->id">{{ $sector->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        @error('transferSectorId')
                            <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                        @enderror
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
                        <flux:select wire:model="closeOutcome" label="Motivo do encerramento" placeholder="Escolha o motivo">
                            @foreach (\App\Enums\CloseOutcome::cases() as $outcome)
                                <flux:select.option :value="$outcome->value">{{ $outcome->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        @error('closeOutcome')
                            <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                        @enderror
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
                        <flux:select wire:model="reassignUserId" label="Reatribuir para" placeholder="Escolha a pessoa">
                            @foreach ($candidates as $candidate)
                                <flux:select.option :value="$candidate->id">{{ $candidate->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        @error('reassignUserId')
                            <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                        @enderror
                    </div>
                    <flux:button type="submit" variant="primary" size="sm">Reatribuir</flux:button>
                    <flux:button type="button" size="sm" variant="subtle" wire:click="closePanel">Cancelar</flux:button>
                </form>
            @endif

            <livewire:inbox.contact-tickets :ticket-id="$ticket->id" :key="'contact-tickets-'.$ticket->id" />

            @if ($notice)
                <div class="border-b border-warn/30 bg-warn/10 px-4 py-2 text-sm text-warn" role="alert">{{ $notice }}</div>
            @endif

            <div class="flex min-h-0 flex-1 flex-col-reverse overflow-y-auto px-4 py-4">
                <livewire:inbox.transcript :ticket-id="$ticket->id" :key="'transcript-'.$ticket->id" />
            </div>

            <footer class="border-t border-line bg-surface p-3">
                @if ($canReply && $ticket->channel->status !== \App\Enums\ChannelStatus::WORKING)
                    <p class="mb-2 rounded-md bg-warn/10 px-3 py-2 text-xs text-warn" role="status">
                        O WhatsApp deste canal está desconectado. As mensagens ficam aguardando e saem sozinhas quando a conexão voltar (por até 24 h).
                    </p>
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
                                        <button type="button" wire:click="removeAttachment({{ $index }})" class="text-ink-3 hover:text-danger" aria-label="Remover {{ $file->getClientOriginalName() }}">
                                            <flux:icon.x-mark class="size-3.5" />
                                        </button>
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
                                <button type="button" class="text-sm text-ink-2 hover:text-danger" x-on:click="discard()">Descartar</button>
                                <button type="button" class="rounded-md bg-accent px-3 py-1 text-sm font-medium text-white" x-on:click="stop()">Parar</button>
                            </div>

                            <div x-show="phase === 'preview'" x-cloak class="flex items-center gap-2 rounded-md border border-line bg-canvas px-3 py-2">
                                <audio x-bind:src="url" controls class="h-9 min-w-0 flex-1"></audio>
                                <button type="button" class="text-sm text-ink-2 hover:text-ink" x-on:click="rerecord()" x-bind:disabled="sending">Regravar</button>
                                <button type="button" class="text-sm text-ink-2 hover:text-danger" x-on:click="discard()" x-bind:disabled="sending">Descartar</button>
                                <button type="button" class="rounded-md bg-accent px-3 py-1 text-sm font-medium text-white disabled:opacity-50" x-on:click="send()" x-bind:disabled="sending">Enviar</button>
                            </div>
                        </div>

                        @error('voice')
                            <p class="mb-2 text-xs text-danger" role="alert">{{ $message }}</p>
                        @enderror

                        <form wire:submit="send" class="flex items-end gap-2" x-show="!voiceBusy">
                            <input
                                type="file"
                                multiple
                                class="hidden"
                                x-ref="picker"
                                aria-label="Escolher arquivos"
                                accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.xls,.xlsx,.csv,.mp3,.ogg,.m4a,.mp4"
                                x-on:change="addFiles($event.target.files); $event.target.value = ''"
                            >
                            <flux:button type="button" variant="subtle" icon="paper-clip" aria-label="Anexar arquivo" x-on:click="$refs.picker.click()" />
                            <flux:button type="button" variant="subtle" icon="microphone" aria-label="Gravar áudio" x-on:click="$dispatch('voice-start')" />
                            <flux:button type="button" variant="{{ $sign ? 'filled' : 'subtle' }}" icon="pencil-square" aria-pressed="{{ $sign ? 'true' : 'false' }}" aria-label="Assinatura {{ $sign ? 'ligada' : 'desligada' }}" title="Assinatura {{ $sign ? 'ligada' : 'desligada' }}" wire:click="toggleSign" />

                            <div class="flex-1">
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
                                <flux:textarea wire:model="draft" rows="auto" placeholder="Escreva uma resposta" aria-label="Mensagem" x-on:input.debounce.250ms="if ($event.target.value.startsWith('/')) { $wire.$set('draft', $event.target.value) }" x-on:keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); if (uploading === 0) { $wire.send() } }" />
                                @error('draft')
                                    <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                                @enderror
                            </div>
                            <flux:button type="submit" variant="primary" icon="paper-airplane" aria-label="Enviar" x-bind:disabled="uploading > 0" />
                        </form>
                    </div>
                @elseif ($ticket->status === \App\Enums\TicketStatus::ENCERRADO)
                    <p class="text-center text-sm text-ink-3">Ticket encerrado. Somente leitura.</p>
                @elseif (! $ticket->channel->active)
                    <p class="text-center text-sm text-ink-3" role="status">Canal desativado. Reative para responder.</p>
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

    {{-- Detalhes --}}
    @if ($ticket)
        <x-ticket-details :ticket="$ticket" :tz="$tz" />
    @endif
</div>
