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
     * `$version` é a versão atual gravada da imagem: o cache só é imutável se ela existe e a URL pedida a traz (`v`); sem uma das
     * duas (ou com versão velha) o navegador revalida pelo ETag.
     */
    public function response(string $path, bool $public, ?string $version = null): Response
    {
        $versioned = $version !== null && request()->query('v') === $version;
        $etag = '"'.basename($path, '.webp').'"';
        $headers = [
            'Content-Type' => 'image/webp',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => ($public ? 'public' : 'private').', '.($versioned ? 'max-age='.self::ONE_YEAR.', immutable' : 'no-cache'),
            'ETag' => $etag,
        ];

        // Em If-None-Match vale a comparação fraca: `W/"x"` casa com `"x"`. O `*` fica sem 304 de propósito (serviria objeto sumido).
        $sent = array_map(fn (string $tag): string => (string) preg_replace('#^W/#', '', $tag), request()->getETags());

        if (in_array($etag, $sent, true)) {
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
