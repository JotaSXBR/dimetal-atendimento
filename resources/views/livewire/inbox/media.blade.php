@php
    $mime = (string) $message->media_mime;
    $url = $message->media_ref !== null || $message->local_path !== null ? route('midia', $message) : null;
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
    @include('livewire.inbox.location', ['message' => $message])
@elseif ($message->type === 'contact')
    @include('livewire.inbox.contact', ['message' => $message])
@elseif ($url === null && $message->media_purged_at !== null)
    <p class="text-ink-2 italic">Arquivo expirado (os anexos são guardados por 90 dias).</p>
@elseif ($url === null)
    <p class="text-ink-2 italic">{{ $message->created_at?->gt(now()->subMinutes(2)) ? 'Carregando mídia…' : 'Mídia indisponível' }}</p>
@elseif (str_starts_with($mime, 'image/'))
    @if ($mime === 'image/webp')
        {{-- Figurinha (webp) é pequena. --}}
        <a href="{{ $url }}" target="_blank" rel="noopener"><img src="{{ $url }}" alt="Figurinha recebida" loading="lazy" class="max-h-40 rounded-lg"></a>
    @else
        {{-- Foto como no WhatsApp: largura fixa e altura máxima; a mais alta que o limite é cortada. --}}
        <a data-photo-box href="{{ $url }}" target="_blank" rel="noopener" class="block w-72 max-w-full overflow-hidden rounded-lg"><img src="{{ $url }}" alt="Imagem recebida" loading="lazy" class="max-h-80 w-full object-cover"></a>
    @endif
@elseif (str_starts_with($mime, 'audio/'))
    @include('livewire.inbox.audio-player', ['url' => $url])
@elseif (str_starts_with($mime, 'video/'))
    <video controls preload="metadata" src="{{ $url }}" class="max-h-72 max-w-full rounded-lg"></video>
@else
    {{-- Documento como o cartão do WhatsApp: ícone do tipo, nome, "TIPO · tamanho" e, sob um filete, um botão (Abrir no PDF, Baixar no resto) --}}
    @php
        $extension = \App\Support\MediaPresentation::extension($message->media_name, $mime);
        $badge = \App\Support\MediaPresentation::badge($extension);
        $meta = implode(' · ', array_filter([$extension, \App\Support\MediaPresentation::size($message->media_size)]));
        $tones = ['blue' => 'bg-blue-600', 'green' => 'bg-green-600', 'orange' => 'bg-orange-500', 'red' => 'bg-red-600', 'gray' => 'bg-zinc-400'];
    @endphp
    <div data-document-card class="w-72 max-w-full overflow-hidden rounded-lg border border-line bg-canvas/60">
        <div class="flex items-center gap-3 px-3 py-2">
            <span class="flex size-10 shrink-0 items-center justify-center rounded-md text-xs font-bold text-white {{ $tones[$badge['tone']] }}">
                @if ($badge['label'] !== '')
                    {{ $badge['label'] }}
                @else
                    <flux:icon.document-text class="size-5" />
                @endif
            </span>
            <span class="min-w-0 flex-1">
                <span class="block truncate text-sm font-medium text-ink">{{ $message->media_name ?? 'Arquivo' }}</span>
                @if ($meta !== '')
                    <span data-document-meta class="block text-xs text-ink-3">{{ $meta }}</span>
                @endif
            </span>
        </div>
        {{-- Um botão só: o navegador não aciona o app do computador; PDF ele mostra na aba, o resto só baixa (e o sistema abre com o app padrão). --}}
        <div class="border-t border-line text-center text-sm font-medium text-accent">
            @if ($mime === 'application/pdf')
                <a href="{{ $url }}" target="_blank" rel="noopener" class="block py-1.5 hover:bg-canvas">Abrir</a>
            @else
                <a href="{{ $url }}" download="{{ $message->media_name ?? 'arquivo' }}" class="block py-1.5 hover:bg-canvas">Baixar</a>
            @endif
        </div>
    </div>
@endif
