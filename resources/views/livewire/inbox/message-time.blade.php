{{-- Horário (e tiques) da bolha, sempre no canto inferior direito. `inline`: flutua no fim do texto e cai para a linha de baixo
     quando não cabe; `overlay`: sobre foto/vídeo sem legenda; `audio`: na linha da duração do áudio;
     `below`: na base da bolha (documento, localização, figurinha). --}}
<span data-testid="message-time" data-time-mode="{{ $mode }}" @class([
    // `whitespace-nowrap`: no modo `inline` o trecho está dentro de um `<p class="whitespace-pre-wrap">` e herdaria o `pre-wrap`, que
    // preserva as quebras de linha e os recuos deste arquivo (a hora subia, os tiques ficavam no meio e abria um vão entre os dois).
    'items-center gap-1 font-mono text-[10px] whitespace-nowrap',
    'float-right ms-3 mt-2 inline-flex text-ink-2' => $mode === 'inline',
    'mt-1 flex justify-end text-ink-2' => $mode === 'below',
    'absolute end-0 bottom-0 inline-flex leading-4 text-ink-2' => $mode === 'audio',
    'pointer-events-none absolute end-1.5 bottom-1.5 inline-flex rounded bg-black/55 px-1.5 py-0.5 text-white' => $mode === 'overlay',
])>
    @if ($message->edited_at)
        <span>editada</span>
    @endif
    {{ $message->sent_at->timezone($tz)->format('H:i') }}
    @if ($out)
        <span class="font-bold {{ $tick[2] }}" aria-label="{{ $tick[0] }}">{{ $tick[1] }}</span>
    @endif
</span>
