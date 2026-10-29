@php
    $mime = (string) $message->media_mime;
    $url = $message->media_ref !== null ? route('midia', $message) : null;
@endphp

@if ($message->type === 'view_once')
    <p class="text-ink-2 italic">O cliente enviou uma mensagem de visualização única; abra no celular.</p>
@elseif ($url === null)
    <p class="text-ink-2 italic">{{ $message->created_at?->gt(now()->subMinutes(2)) ? 'Carregando mídia…' : 'Mídia indisponível' }}</p>
@elseif (str_starts_with($mime, 'image/'))
    <a href="{{ $url }}" target="_blank" rel="noopener"><img src="{{ $url }}" alt="Imagem recebida" loading="lazy" class="max-h-72 rounded-lg"></a>
@elseif (str_starts_with($mime, 'audio/'))
    <audio controls preload="metadata" src="{{ $url }}" class="w-64 max-w-full"></audio>
@elseif (str_starts_with($mime, 'video/'))
    <video controls preload="metadata" src="{{ $url }}" class="max-h-72 max-w-full rounded-lg"></video>
@else
    <a href="{{ $url }}" download class="text-accent underline">{{ $message->media_name ?? 'Baixar arquivo' }}</a>
@endif
