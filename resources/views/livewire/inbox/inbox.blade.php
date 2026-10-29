@php
    $tz = config('app.display_timezone');
    $statusColor = fn (\App\Enums\TicketStatus $s) => match ($s) {
        \App\Enums\TicketStatus::AGUARDANDO => 'amber',
        \App\Enums\TicketStatus::EM_ATENDIMENTO => 'green',
        default => 'zinc',
    };
    $tabs = ['todos' => 'Todos', 'aguardando' => 'Aguardando', 'meus' => 'Meus'];
@endphp

<div class="grid h-dvh grid-cols-1 bg-surface lg:grid-cols-[340px_minmax(0,1fr)_280px]">
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
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </header>

        <div class="min-h-0 flex-1 overflow-y-auto">
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
                            <flux:avatar :name="$item->contact->name ?? $item->contact->wa_id" size="sm" />

                            <span class="min-w-0 flex-1">
                                <span class="flex items-baseline justify-between gap-2">
                                    <span class="truncate text-sm font-semibold text-ink">{{ $item->contact->name ?? $item->contact->wa_id }}</span>
                                    <span class="font-mono text-[10px] text-ink-3">
                                        {{ ($item->last_inbound_at ?? $item->opened_at)->timezone($tz)->format('H:i') }}
                                    </span>
                                </span>
                                <span class="mt-0.5 block truncate text-xs text-ink-2">{{ $item->preview }}</span>
                            </span>
                        </button>
                    @endforeach
                @endif
            @endforeach

            @if ($waiting->isEmpty() && $active->isEmpty())
                <p class="p-6 text-center text-sm text-ink-3">Nenhum ticket por aqui.</p>
            @endif
        </div>
    </section>

    {{-- Conversa --}}
    <section class="flex min-h-0 flex-col bg-canvas {{ $ticket ? '' : 'hidden lg:flex' }}">
        @if ($ticket)
            @php
                $name = $ticket->contact->name ?? $ticket->contact->wa_id;
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
                        {{ $ticket->protocol }} · {{ $ticket->contact->wa_id ?? $ticket->contact->lid }} · {{ $ticket->channel->name }}
                    </p>
                </div>

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
                    <flux:button size="sm" wire:click="close" wire:confirm="Encerrar este ticket? A próxima mensagem do cliente abre um ticket novo.">Encerrar</flux:button>
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

            @if ($notice)
                <div class="border-b border-warn/30 bg-warn/10 px-4 py-2 text-sm text-warn" role="alert">{{ $notice }}</div>
            @endif

            <div class="flex min-h-0 flex-1 flex-col-reverse overflow-y-auto px-4 py-4">
                <div class="space-y-2">
                    @foreach ($messages as $message)
                        @php
                            $out = $message->direction === \App\Enums\MessageDirection::OUT;
                            $outside = $message->origin === \App\Enums\MessageOrigin::FORA_DO_PAINEL;
                            $tick = match ($message->delivery_status) {
                                \App\Enums\DeliveryStatus::FAILED => ['Falhou', 'falhou ao enviar', 'text-danger'],
                                \App\Enums\DeliveryStatus::READ => ['Lida', '✓✓', 'text-sky-600'],
                                \App\Enums\DeliveryStatus::DELIVERED => ['Entregue', '✓✓', ''],
                                \App\Enums\DeliveryStatus::SENT => ['Enviada', '✓', ''],
                                default => ['Enviando', '…', ''],
                            };
                        @endphp

                        <div wire:key="message-{{ $message->id }}" class="flex {{ $out ? 'justify-end' : 'justify-start' }}">
                            <div class="max-w-[75%] rounded-xl px-3 py-2 text-sm text-ink {{ $outside ? 'bg-warn/10' : ($out ? 'bg-accent-soft' : 'bg-surface') }}">
                                @if ($outside)
                                    <p class="mb-0.5 font-mono text-[10px] tracking-wide text-warn uppercase">Celular · fora do painel</p>
                                @endif

                                @if ($message->hasMedia() || $message->type === 'view_once')
                                    @include('livewire.inbox.media', ['message' => $message])
                                @endif

                                @if ($message->body !== '')
                                    <p class="break-words whitespace-pre-wrap">{{ $message->body }}</p>
                                @elseif (! $message->hasMedia() && $message->type !== 'view_once')
                                    <p class="break-words whitespace-pre-wrap">[{{ $message->type }}]</p>
                                @endif

                                @if ($message->failure_reason)
                                    <p class="mt-1 text-xs text-danger">{{ $message->failure_reason }}</p>
                                @endif

                                <p class="mt-1 flex items-center justify-end gap-1 font-mono text-[10px] text-ink-3">
                                    {{ $message->sent_at->timezone($tz)->format('H:i') }}
                                    @if ($out)
                                        <span class="{{ $tick[2] }}" aria-label="{{ $tick[0] }}">{{ $tick[1] }}</span>
                                    @endif
                                </p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <footer class="border-t border-line bg-surface p-3">
                @if ($canReply)
                    <form wire:submit="send" class="flex items-end gap-2">
                        <div class="flex-1">
                            <flux:textarea wire:model="draft" rows="auto" placeholder="Escreva uma resposta" aria-label="Mensagem" x-on:keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); $wire.send() }" />
                            @error('draft')
                                <p class="mt-1 text-xs text-danger">{{ $message }}</p>
                            @enderror
                        </div>
                        <flux:button type="submit" variant="primary" icon="paper-airplane" aria-label="Enviar" />
                    </form>
                @elseif ($ticket->status === \App\Enums\TicketStatus::ENCERRADO)
                    <p class="text-center text-sm text-ink-3">Ticket encerrado. Somente leitura.</p>
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
    <aside class="hidden overflow-y-auto border-s border-line p-4 lg:block">
        @if ($ticket)
            <h2 class="font-mono text-[10px] font-semibold tracking-wider text-ink-3 uppercase">Detalhes do ticket</h2>

            <dl class="mt-3 space-y-3 text-sm">
                <div>
                    <dt class="text-xs text-ink-3">Canal</dt>
                    <dd class="text-ink">{{ $ticket->channel->name }}{{ $ticket->channel->phone ? ' · '.$ticket->channel->phone : '' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-3">Protocolo</dt>
                    <dd class="font-mono text-ink">{{ $ticket->protocol }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-3">Setor</dt>
                    <dd class="text-ink">{{ $ticket->sector?->name ?? 'Fila geral' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-3">Responsável</dt>
                    <dd class="text-ink">{{ $ticket->assignee?->name ?? 'Não atribuído' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-ink-3">Aberto em</dt>
                    <dd class="font-mono text-ink">{{ $ticket->opened_at->timezone($tz)->format('d/m/Y H:i') }}</dd>
                </div>
            </dl>
        @endif
    </aside>
</div>
