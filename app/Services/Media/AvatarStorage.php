<?php

namespace App\Services\Media;

use App\Models\Message;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /** Repassa o objeto em stream. O `ETag` é o nome do objeto (único por upload); 404 se o objeto não existe. */
    public function response(string $path, bool $public): StreamedResponse
    {
        $disk = Message::mediaDisk();
        $stream = $disk->exists($path) ? $disk->readStream($path) : null;

        abort_if($stream === null, 404);

        $response = response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => 'image/webp',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => ($public ? 'public' : 'private').', max-age='.self::ONE_YEAR.', immutable',
        ]);
        $response->setEtag(basename($path, '.webp'));
        $response->isNotModified(request());

        return $response;
    }
}
