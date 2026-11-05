{{-- Horário (e tiques) da bolha. `inline`: flutua à direita no fim do texto e cai para a linha de baixo quando não cabe. --}}
<span data-testid="message-time" class="{{ $inline ? 'float-right ms-3 mt-2' : 'mt-1 flex justify-end' }} inline-flex items-center gap-1 font-mono text-[10px] text-ink-3">
    @if ($message->edited_at)
        <span>editada</span>
    @endif
    {{ $message->sent_at->timezone($tz)->format('H:i') }}
    @if ($out)
        <span class="font-bold {{ $tick[2] }}" aria-label="{{ $tick[0] }}">{{ $tick[1] }}</span>
    @endif
</span>
