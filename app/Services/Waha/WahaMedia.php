<?php

namespace App\Services\Waha;

use Illuminate\Http\Client\Response;

/**
 * Mídia no WAHA: pedir o download de uma mensagem, achar o arquivo guardado e lê-lo em stream.
 */
class WahaMedia
{
    /** O WAHA baixa a mídia do WhatsApp antes de responder: foto em HD, vídeo e documento passam dos 15 s padrão. */
    private const DOWNLOAD_TIMEOUT_SECONDS = 90;

    public function __construct(private readonly WahaClient $client) {}

    /**
     * Pede ao WAHA que baixe a mídia da mensagem e devolve o que ele diz sobre o arquivo. O `media.url` do GOWS não
     * serve (CDN cifrado ou endereço interno) e o nome em `media.filename` nem sempre é o do arquivo guardado; quem
     * decide o nome certo é `firstStoredFile`.
     *
     * @return array{filename: ?string, mimetype: ?string}|null `null` enquanto a resposta não traz `media`
     */
    public function downloadMessageMedia(string $chatId, string $waId): ?array
    {
        $response = $this->client->http()->timeout(self::DOWNLOAD_TIMEOUT_SECONDS)->get(
            $this->sessionPath('/chats/'.rawurlencode($chatId).'/messages/'.rawurlencode($waId)),
            ['downloadMedia' => 'true'],
        );
        $this->client->check($response);

        $media = $response->json('media');

        if (! is_array($media)) {
            return null;
        }

        $filename = $media['filename'] ?? $this->filenameFromUrl($media['url'] ?? null);
        $mimetype = $media['mimetype'] ?? null;

        return [
            'filename' => is_string($filename) && $filename !== '' ? $filename : null,
            'mimetype' => is_string($mimetype) ? $mimetype : null,
        ];
    }

    /**
     * O primeiro nome da lista que existe no armazenamento do WAHA (`/api/files/{sessão}/{nome}`, depois
     * `/api/files/{nome}`). Pede só o primeiro byte; o corpo nunca é lido.
     *
     * @param  list<string>  $names
     */
    public function firstStoredFile(array $names): ?string
    {
        foreach ($names as $name) {
            foreach (['/api/files/'.rawurlencode($this->client->sessionName()).'/'.rawurlencode($name), '/api/files/'.rawurlencode($name)] as $path) {
                $status = $this->client->http()->withHeaders(['Range' => 'bytes=0-0', 'Accept-Encoding' => 'identity'])->withOptions(['stream' => true])->get($path)->status();

                if ($status === 200 || $status === 206) {
                    return $name;
                }
            }
        }

        return null;
    }

    /**
     * Só o último trecho de uma URL do próprio WAHA (`/api/files/...`) vira nome; a URL em si nunca é buscada.
     */
    private function filenameFromUrl(mixed $url): ?string
    {
        $path = is_string($url) ? parse_url($url, PHP_URL_PATH) : null;

        return is_string($path) && str_contains($path, '/api/files/') ? rawurldecode(basename($path)) : null;
    }

    /**
     * Lê um arquivo guardado no WAHA, repassando o `Range` do navegador (o Safari exige para tocar áudio e vídeo).
     * O WAHA serve `/api/files/{sessão}/{arquivo}`; a rota sem a sessão é a tentativa de reserva.
     * Devolve `null` quando o arquivo não existe.
     */
    public function streamFile(string $ref, ?string $range = null): ?Response
    {
        foreach (['/api/files/'.rawurlencode($this->client->sessionName()).'/'.rawurlencode($ref), '/api/files/'.rawurlencode($ref)] as $path) {
            // `identity`: com o corpo comprimido, o `Content-Length` repassado não bateria com os bytes entregues.
            $request = $this->client->http()->withOptions(['stream' => true])->withHeaders(['Accept-Encoding' => 'identity'] + ($range === null ? [] : ['Range' => $range]));
            $response = $request->get($path);

            if ($response->status() === 404) {
                continue;
            }

            if ($response->status() !== 416) {
                $this->client->check($response);
            }

            return $response;
        }

        return null;
    }

    private function sessionPath(string $suffix): string
    {
        return '/api/'.$this->client->sessionName().$suffix;
    }
}
