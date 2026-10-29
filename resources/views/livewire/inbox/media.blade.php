@php
    $mime = (string) $message->media_mime;
    $url = $message->media_ref !== null ? route('midia', $message) : null;
@endphp

@if ($message->type === 'view_once')
    <div class="flex items-start gap-2 text-ink-2">
        <flux:icon.eye-slash class="mt-0.5 size-4 shrink-0" />
        <p>
            <span class="font-medium">Mensagem de visualização única.</span>
            O WhatsApp não entrega esse conteúdo ao atendimento: abra no celular ou peça ao cliente que reenvie como foto ou vídeo normal.
        </p>
    </div>
@elseif ($url === null)
    <p class="text-ink-2 italic">{{ $message->created_at?->gt(now()->subMinutes(2)) ? 'Carregando mídia…' : 'Mídia indisponível' }}</p>
@elseif (str_starts_with($mime, 'image/'))
    <a href="{{ $url }}" target="_blank" rel="noopener"><img src="{{ $url }}" alt="Imagem recebida" loading="lazy" class="max-h-72 rounded-lg"></a>
@elseif (str_starts_with($mime, 'audio/'))
    @include('livewire.inbox.audio-player', ['url' => $url])
@elseif (str_starts_with($mime, 'video/'))
    <video controls preload="metadata" src="{{ $url }}" class="max-h-72 max-w-full rounded-lg"></video>
@else
    <a href="{{ $url }}" download class="text-accent underline">{{ $message->media_name ?? 'Baixar arquivo' }}</a>
@endif
