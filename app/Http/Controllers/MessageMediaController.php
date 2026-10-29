<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Services\Inbox\IncomingMedia;
use App\Services\Waha\WahaClient;
use App\Services\Waha\WahaMedia;
use Illuminate\Http\Client\Response as UpstreamResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Repassa ao navegador o arquivo que o WAHA guarda. O navegador nunca fala com o WAHA nem recebe a chave de API.
 */
class MessageMediaController extends Controller
{
    /** Tipos mostrados na própria tela; qualquer outro vira download. */
    private const INLINE_TYPES = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        'audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/webm',
        'video/mp4', 'video/webm',
        'application/pdf',
    ];

    public function __invoke(Request $request, Message $message): Response
    {
        Gate::authorize('view', $message->ticket()->firstOrFail());

        // Anexo enviado pelo painel: a cópia local vale mais que a do WAHA (e é a única enquanto o eco não volta).
        if ($message->local_path !== null && Storage::disk('local')->exists($message->local_path)) {
            return $this->serveLocal($message);
        }

        $ref = $this->safeRef($message);
        $upstream = (new WahaMedia(new WahaClient($message->channel()->firstOrFail())))->streamFile($ref, $request->header('Range'));

        abort_if($upstream === null, 404);

        $body = $upstream->toPsrResponse()->getBody();

        return response()->stream(function () use ($body): void {
            // Vídeo grande em conexão lenta passa dos 30 s padrão do PHP; os bytes enviados contam contra o limite.
            set_time_limit(0);

            while (! $body->eof()) {
                echo $body->read(8192);
                flush();
            }
        }, in_array($upstream->status(), [206, 416], true) ? $upstream->status() : 200, $this->headersFor($message, $ref, $upstream));
    }

    private function serveLocal(Message $message): BinaryFileResponse
    {
        $inline = in_array($message->media_mime, self::INLINE_TYPES, true);
        $response = response()->file(Storage::disk('local')->path((string) $message->local_path), [
            'Content-Type' => $inline ? (string) $message->media_mime : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
        ]);

        if (! $inline) {
            $response->setContentDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, (string) ($message->media_name ?? 'arquivo'), 'arquivo');
        }

        return $response;
    }

    /**
     * O nome vem de um evento externo: nada que saia do diretório de arquivos chega ao WAHA.
     */
    private function safeRef(Message $message): string
    {
        $ref = IncomingMedia::safeRef($message->media_ref);

        abort_unless($message->hasMedia() && $ref !== null, 404);

        return $ref;
    }

    /**
     * @return array<string, string>
     */
    private function headersFor(Message $message, string $ref, UpstreamResponse $upstream): array
    {
        $inline = in_array($message->media_mime, self::INLINE_TYPES, true);
        $headers = [
            'Content-Type' => $inline ? (string) $message->media_mime : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
            'Accept-Ranges' => 'bytes',
            // Sem isto o nginx grava a resposta inteira em disco antes de entregar, e o vídeo só começa ao fim.
            'X-Accel-Buffering' => 'no',
        ];

        foreach (['Content-Range', 'Content-Length'] as $name) {
            if ($upstream->header($name) !== '') {
                $headers[$name] = $upstream->header($name);
            }
        }

        if (! $inline) {
            $headers['Content-Disposition'] = HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $message->media_name ?? $ref, 'arquivo');
        }

        return $headers;
    }
}
