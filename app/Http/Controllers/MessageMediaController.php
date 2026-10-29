<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Services\Waha\WahaClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Repassa ao navegador o arquivo que o WAHA guarda. O navegador nunca fala com o WAHA nem recebe a chave de API.
 */
class MessageMediaController extends Controller
{
    /** Tipos mostrados na própria tela; qualquer outro vira download. */
    private const INLINE_TYPES = [
        'image/jpeg', 'image/png', 'image/webp', 'image/gif',
        'audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/aac',
        'video/mp4', 'video/webm',
        'application/pdf',
    ];

    public function __invoke(Request $request, Message $message): StreamedResponse
    {
        Gate::authorize('view', $message->ticket()->firstOrFail());

        $ref = $message->media_ref;

        // O nome vem de um evento externo: nada que saia do diretório de arquivos chega ao WAHA.
        if (! $message->hasMedia() || $ref === null || preg_match('/^[A-Za-z0-9._-]{1,255}$/', $ref) !== 1 || str_contains($ref, '..')) {
            abort(404);
        }

        $upstream = (new WahaClient($message->channel()->firstOrFail()))->streamFile($ref, $request->header('Range'));

        abort_if($upstream === null, 404);

        $inline = in_array($message->media_mime, self::INLINE_TYPES, true);
        $headers = [
            'Content-Type' => $inline ? $message->media_mime : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=3600',
            'Accept-Ranges' => 'bytes',
        ];

        foreach (['Content-Range', 'Content-Length'] as $name) {
            if ($upstream->header($name) !== '') {
                $headers[$name] = $upstream->header($name);
            }
        }

        if (! $inline) {
            $headers['Content-Disposition'] = 'attachment; filename="'.addcslashes($message->media_name ?? $ref, '"\\').'"';
        }

        $body = $upstream->toPsrResponse()->getBody();

        return response()->stream(function () use ($body): void {
            while (! $body->eof()) {
                echo $body->read(8192);
                flush();
            }
        }, $upstream->status() === 206 ? 206 : 200, $headers);
    }
}
