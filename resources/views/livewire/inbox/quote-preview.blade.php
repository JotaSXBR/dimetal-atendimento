{{--
    O interior de uma citação, como no WhatsApp: autor, o trecho com um ícone do tipo e, para foto e figurinha, a miniatura quadrada
    à direita. `$quote` é o snapshot gravado com a mensagem; `$heading` troca o título (a barra do campo diz "Respondendo a …").
    Citação antiga, sem `media`, é só texto. A miniatura some (Alpine, sem atributo inline por causa da CSP) se a mídia já não estiver mais lá (expirada).
--}}
@php
    $media = $quote['media'] ?? null;
    $kind = is_array($media) ? ($media['kind'] ?? null) : null;
@endphp

<span class="flex items-start gap-2">
    <span class="min-w-0 flex-1">
        <span class="block truncate font-semibold text-accent">{{ $heading ?? ($quote['author'] ?? '') }}</span>
        <span class="flex items-center gap-1 text-ink-2">
            @if ($kind === 'image' || $kind === 'sticker')
                <flux:icon.photo class="size-3.5 shrink-0" />
            @elseif ($kind === 'document')
                <flux:icon.document-text class="size-3.5 shrink-0" />
            @elseif ($kind === 'video')
                <flux:icon.video-camera class="size-3.5 shrink-0" />
            @elseif ($kind === 'audio')
                <flux:icon.microphone class="size-3.5 shrink-0" />
            @endif
            <span class="truncate">{{ \App\Support\WhatsAppText::html((string) ($quote['excerpt'] ?? '')) }}</span>
        </span>
    </span>

    @if (($kind === 'image' || $kind === 'sticker') && is_int($media['message_id'] ?? null))
        <img src="{{ route('midia', $media['message_id']) }}" alt="" loading="lazy" x-on:error="$el.style.display = 'none'" class="size-14 shrink-0 rounded object-cover">
    @endif
</span>
