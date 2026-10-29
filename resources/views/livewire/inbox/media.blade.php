@php
    $mime = (string) $message->media_mime;
    $url = $message->media_ref !== null ? route('midia', $message) : null;
    $kind = match (true) {
        str_starts_with($mime, 'image/') => 'Foto',
        str_starts_with($mime, 'video/') => 'Vídeo',
        str_starts_with($mime, 'audio/') => 'Áudio',
        default => 'Mensagem',
    };
@endphp

@if ($message->type === 'view_once')
    <div class="flex items-start gap-2 text-ink-2">
        <flux:icon.eye-slash class="mt-0.5 size-4 shrink-0" />
        <p>
            <span class="font-medium">{{ $kind }} de visualização única.</span>
            O WhatsApp não entrega esse conteúdo ao atendimento: abra no celular ou peça ao cliente que reenvie como foto ou vídeo normal.
        </p>
    </div>
@elseif ($message->type === 'location')
    <a href="https://www.google.com/maps?q={{ urlencode($message->body) }}" target="_blank" rel="noopener" class="flex items-center gap-2 text-accent underline">
        <flux:icon.map-pin class="size-4 shrink-0" />
        <span>{{ $message->media_name ?? 'Localização' }}<span class="block font-mono text-[10px] text-ink-3 no-underline">{{ $message->body }}</span></span>
    </a>
@elseif ($url === null)
    <p class="text-ink-2 italic">{{ $message->created_at?->gt(now()->subMinutes(2)) ? 'Carregando mídia…' : 'Mídia indisponível' }}</p>
@elseif (str_starts_with($mime, 'image/'))
    {{-- Figurinha (webp) é pequena; foto usa a altura cheia. --}}
    <a href="{{ $url }}" target="_blank" rel="noopener"><img src="{{ $url }}" alt="{{ $mime === 'image/webp' ? 'Figurinha recebida' : 'Imagem recebida' }}" loading="lazy" class="{{ $mime === 'image/webp' ? 'max-h-40' : 'max-h-72' }} rounded-lg"></a>
@elseif (str_starts_with($mime, 'audio/'))
    @include('livewire.inbox.audio-player', ['url' => $url])
@elseif (str_starts_with($mime, 'video/'))
    <video controls preload="metadata" src="{{ $url }}" class="max-h-72 max-w-full rounded-lg"></video>
@else
    <a href="{{ $url }}" download class="text-accent underline">{{ $message->media_name ?? 'Baixar arquivo' }}</a>
@endif
