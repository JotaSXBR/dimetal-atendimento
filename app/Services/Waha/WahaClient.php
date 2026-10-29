<?php

namespace App\Services\Waha;

use App\Models\Channel;
use App\Settings\WahaSettings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cliente HTTP do WAHA para uma sessão (canal).
 *
 * Não guarda cache de configuração: base URL e API key vêm do ambiente (WahaSettings).
 */
class WahaClient
{
    private const TIMEOUT_SECONDS = 15;

    public function __construct(private readonly Channel $channel) {}

    /**
     * @return array<string, mixed>
     */
    public function session(): array
    {
        return $this->decode($this->request()->get('/api/sessions/'.$this->sessionName()));
    }

    public function start(): void
    {
        $existing = $this->request()->get('/api/sessions/'.$this->sessionName());

        if ($existing->status() === 404) {
            $this->ensureOk($this->request()->post('/api/sessions', [
                'name' => $this->sessionName(),
                'start' => true,
                'config' => $this->sessionConfig(),
            ]));

            return;
        }

        $this->ensureOk($existing);

        // Sessão já existe: reaplica a configuração (webhook/HMAC) e inicia (idempotente).
        $this->ensureOk($this->request()->put('/api/sessions/'.$this->sessionName(), [
            'name' => $this->sessionName(),
            'config' => $this->sessionConfig(),
        ]));
        $this->ensureOk($this->request()->post($this->sessionPath('/start', true)));
    }

    /**
     * @return array<string, mixed>
     */
    private function sessionConfig(): array
    {
        return ['webhooks' => [[
            'url' => WahaSettings::webhookUrl(),
            'events' => ['session.status', 'message', 'message.any', 'message.ack', 'message.reaction', 'message.revoked', 'presence.update'],
            'hmac' => ['key' => WahaSettings::webhookSecret()],
            'retries' => ['policy' => 'exponential', 'delaySeconds' => 3, 'attempts' => 8],
        ]]];
    }

    public function stop(): void
    {
        $this->acceptMissing($this->request()->post($this->sessionPath('/stop', true)));
    }

    public function restart(): void
    {
        $this->ensureOk($this->request()->post($this->sessionPath('/restart', true)));
    }

    public function logout(): void
    {
        $this->acceptMissing($this->request()->post('/api/sessions/logout', [
            'name' => $this->sessionName(),
        ]));
    }

    public function qrDataUri(): ?string
    {
        $response = $this->request()
            ->withHeaders(['Accept' => 'image/png'])
            ->get($this->sessionPath('/auth/qr'), ['format' => 'image']);

        if ($response->failed()) {
            return null;
        }

        if (! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode($response->body());
    }

    public function requestPairingCode(string $phoneNumber): string
    {
        $response = $this->request()->post($this->sessionPath('/auth/request-code'), [
            'phoneNumber' => preg_replace('/\D/', '', $phoneNumber),
        ]);

        if ($response->status() === 422) {
            throw new WahaRequestException(422, 'Inicie a sessão antes de pedir o código');
        }

        $body = $this->decode($response);

        $code = $body['code'] ?? null;

        if (! is_string($code) || $code === '') {
            throw new WahaRequestException($response->status(), 'O WAHA não devolveu um código de pareamento');
        }

        return $code;
    }

    /**
     * @return array<string, mixed>
     */
    public function sendText(string $chatId, string $text): array
    {
        return $this->decode($this->request()->post('/api/sendText', [
            'session' => $this->sessionName(),
            'chatId' => $chatId,
            'text' => $text,
        ]));
    }

    /**
     * Pede ao WAHA que baixe a mídia da mensagem e devolve o nome do arquivo guardado. O `media.url` do GOWS não
     * serve (CDN cifrado ou endereço interno); o nome em `media.filename` é o que a rota de mídia lê depois.
     *
     * @return array{filename: string, mimetype: ?string}|null `null` enquanto o WAHA não tem o arquivo
     */
    public function downloadMessageMedia(string $chatId, string $waId): ?array
    {
        $response = $this->request()->get(
            $this->sessionPath('/chats/'.rawurlencode($chatId).'/messages/'.rawurlencode($waId)),
            ['downloadMedia' => 'true'],
        );
        $this->ensureOk($response);

        $filename = $response->json('media.filename') ?? $this->filenameFromUrl($response->json('media.url'));
        $mimetype = $response->json('media.mimetype');

        if (! is_string($filename) || $filename === '') {
            Log::warning('WAHA respondeu sem arquivo aproveitável para a mídia', ['media' => $response->json('media')]);

            return null;
        }

        return ['filename' => $filename, 'mimetype' => is_string($mimetype) ? $mimetype : null];
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
        foreach (['/api/files/'.rawurlencode($this->sessionName()).'/'.rawurlencode($ref), '/api/files/'.rawurlencode($ref)] as $path) {
            // `identity`: com o corpo comprimido, o `Content-Length` repassado não bateria com os bytes entregues.
            $request = $this->request()->withOptions(['stream' => true])->withHeaders(['Accept-Encoding' => 'identity'] + ($range === null ? [] : ['Range' => $range]));
            $response = $request->get($path);

            if ($response->status() === 404) {
                continue;
            }

            if ($response->status() !== 416) {
                $this->ensureOk($response);
            }

            return $response;
        }

        return null;
    }

    /**
     * @param  list<string>  $messageIds  ids das mensagens do cliente; o GOWS só marca como lida com eles
     */
    public function sendSeen(string $chatId, array $messageIds = []): void
    {
        $this->ensureOk($this->request()->post('/api/sendSeen', array_filter([
            'session' => $this->sessionName(),
            'chatId' => $chatId,
            'messageIds' => $messageIds,
        ])));
    }

    public function startTyping(string $chatId): void
    {
        $this->ensureOk($this->request()->post('/api/startTyping', [
            'session' => $this->sessionName(),
            'chatId' => $chatId,
        ]));
    }

    public function stopTyping(string $chatId): void
    {
        $this->ensureOk($this->request()->post('/api/stopTyping', [
            'session' => $this->sessionName(),
            'chatId' => $chatId,
        ]));
    }

    public function setPresence(string $presence): void
    {
        $this->ensureOk($this->request()->post($this->sessionPath('/presence'), [
            'presence' => $presence,
        ]));
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(WahaSettings::baseUrl())
            ->withHeaders(['X-Api-Key' => WahaSettings::apiKey()])
            ->acceptJson()
            ->timeout(self::TIMEOUT_SECONDS);
    }

    private function sessionName(): string
    {
        return $this->channel->waha_session;
    }

    private function sessionPath(string $suffix, bool $management = false): string
    {
        $prefix = $management ? '/api/sessions/' : '/api/';

        return $prefix.$this->sessionName().$suffix;
    }

    private function ensureOk(Response $response): void
    {
        if ($response->failed()) {
            throw new WahaRequestException($response->status(), $this->messageFor($response));
        }
    }

    private function acceptMissing(Response $response): void
    {
        if ($response->status() === 404) {
            return;
        }

        $this->ensureOk($response);
    }

    private function messageFor(Response $response): string
    {
        $body = mb_substr($response->body(), 0, 200);

        $message = $response->json('message') ?? $response->json('exception.message');

        if (is_string($message) && $message !== '') {
            $body = $message;
        }

        return "WAHA respondeu {$response->status()}: {$body}";
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $this->ensureOk($response);

        $body = $response->json();

        return is_array($body) ? array_filter($body, is_string(...), ARRAY_FILTER_USE_KEY) : [];
    }
}
