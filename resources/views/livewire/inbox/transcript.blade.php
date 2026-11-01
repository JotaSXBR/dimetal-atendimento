@php
    $tz = config('app.display_timezone');
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
        $resend = $out && ! $readonly ? app(\App\Services\Inbox\ResendTicketMessage::class)->kind($message, auth()->user()) : 'none';
    @endphp

    <div wire:key="message-{{ $message->id }}" class="flex {{ $out ? 'justify-end' : 'justify-start' }}">
        <div class="max-w-[75%] rounded-xl px-3 py-2 text-sm text-ink {{ $outside ? 'bg-warn/10' : ($out ? 'bg-accent-soft' : 'bg-surface') }}">
            @if ($message->origin === \App\Enums\MessageOrigin::BOT)
                <p class="mb-0.5 font-mono text-[10px] tracking-wide text-ink-3 uppercase">Bot · resposta automática</p>
            @endif

            @if ($outside)
                <p class="mb-0.5 font-mono text-[10px] tracking-wide text-warn uppercase">Celular · fora do painel</p>
            @endif

            @if ($message->hasMedia() || in_array($message->type, ['view_once', 'location', 'contact'], true))
                @include('livewire.inbox.media', ['message' => $message])
            @endif

            @if ($message->body !== '' && ! in_array($message->type, ['location', 'contact'], true))
                <p class="break-words whitespace-pre-wrap">{{ \App\Support\WhatsAppText::html($message->body) }}</p>
            @elseif ($message->body === '' && ! $message->hasMedia() && $message->type !== 'view_once')
                <p class="break-words whitespace-pre-wrap">[{{ $message->type }}]</p>
            @endif

            @if ($message->failure_reason)
                <p class="mt-1 text-xs text-danger">{{ $message->failure_reason }}</p>
            @endif

            @if (! $readonly && $message->delivery_status === \App\Enums\DeliveryStatus::QUEUED)
                <p class="mt-1 text-xs text-warn">Aguardando o WhatsApp reconectar. A mensagem sai sozinha quando a conexão voltar.</p>
            @endif

            @if ($resend === 'safe')
                <button type="button" wire:click="resend({{ $message->id }}, true)" class="mt-1 text-xs text-accent underline">Reenviar</button>
            @elseif ($resend === 'confirm')
                <button type="button" wire:click="resend({{ $message->id }}, true)" wire:confirm="Esta mensagem pode já ter sido enviada. Confira no celular do cliente antes de reenviar. Reenviar mesmo assim?" class="mt-1 text-xs text-accent underline">Reenviar</button>
            @endif

            <p class="mt-1 flex items-center justify-end gap-1 font-mono text-[10px] text-ink-3">
                {{ $message->sent_at->timezone($tz)->format('H:i') }}
                @if ($out)
                    <span class="font-bold {{ $tick[2] }}" aria-label="{{ $tick[0] }}">{{ $tick[1] }}</span>
                @endif
            </p>
        </div>
    </div>
@endforeach
