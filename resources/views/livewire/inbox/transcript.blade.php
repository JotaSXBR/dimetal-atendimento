@php
    $tz = config('app.display_timezone');
@endphp

@php
    // Só as mensagens que estão na página têm âncora; a citada que ficou para trás aparece sem rolar até ela.
    $localIds = $messages->pluck('id', 'wa_message_id');
@endphp

@foreach ($messages as $message)
    @php
        $out = $message->direction === \App\Enums\MessageDirection::OUT;
        $outside = $message->origin === \App\Enums\MessageOrigin::FORA_DO_PAINEL;
        $tick = match ($message->delivery_status) {
            \App\Enums\DeliveryStatus::FAILED => ['Falhou', 'falhou ao enviar', 'text-danger'],
            \App\Enums\DeliveryStatus::READ => ['Lida', '✓✓', 'text-sky-600'],
            \App\Enums\DeliveryStatus::DELIVERED => ['Entregue', '✓✓', ''],
            \App\Enums\DeliveryStatus::SENT => ['Enviada', '✓', ''],
            \App\Enums\DeliveryStatus::QUEUED => ['Aguardando conexão', '⏳', 'text-warn'],
            default => ['Enviando', '…', ''],
        };
        // `kind` só devolve algo diferente de `none` para mensagem que falhou: poupa o cálculo nas demais (até 200 por render).
        $resend = $out && ! $readonly && $message->delivery_status === \App\Enums\DeliveryStatus::FAILED
            ? app(\App\Services\Inbox\ResendTicketMessage::class)->kind($message, auth()->user())
            : 'none';
    @endphp

    <div wire:key="message-{{ $message->id }}" id="message-{{ $message->id }}" class="flex {{ $out ? 'justify-end' : 'justify-start' }}">
        <div class="max-w-[75%] rounded-xl px-3 py-2 text-sm text-ink {{ $outside ? 'bg-warn/10' : ($out ? 'bg-accent-soft' : 'bg-surface') }}">
            @if ($message->origin === \App\Enums\MessageOrigin::BOT)
                <p class="mb-0.5 font-mono text-[10px] tracking-wide text-ink-3 uppercase">Bot · resposta automática</p>
            @endif

            @if ($outside)
                <p class="mb-0.5 font-mono text-[10px] tracking-wide text-warn uppercase">Celular · fora do painel</p>
            @endif

            @if ($message->quoted_snapshot)
                <button type="button" data-quote-target="{{ $localIds[$message->quoted_wa_message_id] ?? '' }}" class="mb-1 block w-full rounded-md border-l-4 border-accent bg-canvas/60 px-2 py-1 text-start text-xs">
                    <span class="block font-semibold text-accent">{{ $message->quoted_snapshot['author'] ?? '' }}</span>
                    <span class="block truncate text-ink-2">{{ $message->quoted_snapshot['excerpt'] ?? '' }}</span>
                </button>
            @endif

            @if ($message->revoked_at)
                <p class="mb-0.5 font-mono text-[10px] tracking-wide text-danger uppercase">{{ $out ? 'Mensagem apagada no celular' : 'Mensagem apagada pelo cliente' }}</p>
            @endif

            @if ($message->hasMedia() || in_array($message->type, ['view_once', 'location', 'contact'], true))
                @include('livewire.inbox.media', ['message' => $message])
            @endif

            @if ($message->body !== '' && ! in_array($message->type, ['location', 'contact'], true))
                <p class="break-words whitespace-pre-wrap">{{ \App\Support\WhatsAppText::html($message->body) }}</p>
            @elseif ($message->body === '' && ! $message->hasMedia() && $message->type !== 'view_once')
                <p class="break-words whitespace-pre-wrap">[{{ $message->type }}]</p>
            @endif

            @if ($message->edits->isNotEmpty())
                <div class="mt-1 space-y-0.5 border-l-2 border-line pl-2 text-xs text-ink-3">
                    @foreach ($message->edits as $edit)
                        <p class="break-words whitespace-pre-wrap"><span class="font-mono text-[10px] uppercase">Antes:</span> {{ $edit->previous_body }}</p>
                    @endforeach
                </div>
            @endif

            @if ($message->reactions->isNotEmpty())
                <div class="mt-1 flex flex-wrap gap-1 text-xs">
                    @foreach ($message->reactions as $reaction)
                        <span class="rounded-full border border-line bg-canvas px-1.5 py-0.5" title="{{ $reaction->by === 'me' ? 'Reação do número da empresa' : 'Reação do cliente' }}">{{ $reaction->emoji }}</span>
                    @endforeach
                </div>
            @endif

            @if ($message->failure_reason)
                <p class="mt-1 text-xs text-danger">{{ $message->failure_reason }}</p>
            @endif

            @if (! $readonly && $message->delivery_status === \App\Enums\DeliveryStatus::QUEUED)
                <p class="mt-1 text-xs text-warn">Aguardando o WhatsApp reconectar. A mensagem sai sozinha quando a conexão voltar.</p>
            @endif

            @if ($resend === 'safe')
                <button type="button" wire:click="$parent.resend({{ $message->id }}, true)" class="mt-1 text-xs text-accent underline">Reenviar</button>
            @elseif ($resend === 'confirm')
                <button type="button" wire:click="$parent.resend({{ $message->id }}, true)" wire:confirm="Esta mensagem pode já ter sido enviada. Confira no celular do cliente antes de reenviar. Reenviar mesmo assim?" class="mt-1 text-xs text-accent underline">Reenviar</button>
            @endif

            @if (! $readonly && ! $message->revoked_at)
                @can('reply', $message)
                    <div x-data="{ open: false }" class="relative mt-1 flex justify-end">
                        <button type="button" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-haspopup="menu" aria-label="Ações da mensagem" class="text-xs text-ink-3 hover:text-ink">⋯</button>
                        <div x-cloak x-show="open" x-on:click.outside="open = false" x-on:click="open = false" role="menu" class="absolute end-0 top-full z-10 mt-1 min-w-36 rounded-md border border-line bg-surface p-1 text-xs shadow-md">
                            @can('react', $message)
                                <div class="flex gap-0.5 border-b border-line pb-1" role="group" aria-label="Reagir">
                                    @foreach (\App\Services\Inbox\ReactToMessage::EMOJIS as $emoji)
                                        <button type="button" role="menuitem" wire:click="$parent.react({{ $message->id }}, '{{ $emoji }}')" class="rounded px-1 py-0.5 text-base hover:bg-canvas">{{ $emoji }}</button>
                                    @endforeach
                                </div>
                            @endcan
                            <button type="button" role="menuitem" wire:click="$parent.reply({{ $message->id }})" class="mt-1 block w-full rounded px-2 py-1 text-start hover:bg-canvas">Responder</button>
                        </div>
                    </div>
                @endcan
            @endif

            <p class="mt-1 flex items-center justify-end gap-1 font-mono text-[10px] text-ink-3">
                @if ($message->edited_at)
                    <span>editada</span>
                @endif
                {{ $message->sent_at->timezone($tz)->format('H:i') }}
                @if ($out)
                    <span class="font-bold {{ $tick[2] }}" aria-label="{{ $tick[0] }}">{{ $tick[1] }}</span>
                @endif
            </p>
        </div>
    </div>
@endforeach
