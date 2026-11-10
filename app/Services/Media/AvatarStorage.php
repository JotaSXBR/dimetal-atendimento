<?php

namespace App\Services\Media;

use App\Models\Message;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guarda e entrega as imagens de usuário e o logo no disco de mídia (RustFS). A chave é aleatória e sem dado pessoal; trocar a
 * imagem grava um objeto novo, então o cache longo do navegador nunca serve imagem velha (a URL leva a versão).
 */
class AvatarStorage
{
    private const ONE_YEAR = 31536000;

    /** @return array{path: string, version: string} */
    public function store(string $prefix, string $webp): array
    {
        $path = $prefix.'/'.Str::lower((string) Str::ulid()).'.webp';
        Message::mediaDisk()->put($path, $webp);

        return ['path' => $path, 'version' => substr(sha1($webp), 0, 10)];
    }

    public function delete(?string $path): void
    {
        if ($path !== null && $path !== '') {
            Message::mediaDisk()->delete($path);
        }
    }

    /**
     * Repassa o objeto em stream. O `ETag` é o nome do objeto (único por upload) e quem já o tem recebe 304 sem o disco ser tocado.
     * `$versioned` diz que a URL pedida leva a versão atual da imagem: só então o cache pode ser imutável; sem ela (ou com versão
     * velha) o navegador revalida pelo ETag.
     */
    public function response(string $path, bool $public, bool $versioned = true): Response
    {
        $etag = '"'.basename($path, '.webp').'"';
        $headers = [
            'Content-Type' => 'image/webp',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => ($public ? 'public' : 'private').', '.($versioned ? 'max-age='.self::ONE_YEAR.', immutable' : 'no-cache'),
            'ETag' => $etag,
        ];

        if (in_array($etag, request()->getETags(), true)) {
            return response('', 304, $headers);
        }

        $disk = Message::mediaDisk();
        $stream = $disk->exists($path) ? $disk->readStream($path) : null;

        abort_if($stream === null, 404);

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, $headers);
    }
}
