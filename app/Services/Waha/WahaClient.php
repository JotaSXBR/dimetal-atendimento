<?php

namespace App\Services\Waha;

use App\Models\Channel;
use App\Settings\WahaSettings;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

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
        $response = $this->request()->post('/api/sessions', [
            'name' => $this->sessionName(),
            'start' => true,
            'config' => $this->sessionConfig(),
        ]);

        if ($response->status() === 422) {
            // Sessão já existe: reaplica a configuração (webhook/HMAC) antes de iniciar.
            $this->ensureOk($this->request()->put('/api/sessions/'.$this->sessionName(), [
                'name' => $this->sessionName(),
                'config' => $this->sessionConfig(),
            ]));
            $this->ensureOk($this->request()->post($this->sessionPath('/start', true)));

            return;
        }

        $this->ensureOk($response);
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

    public function sendSeen(string $chatId): void
    {
        $this->ensureOk($this->request()->post('/api/sendSeen', [
            'session' => $this->sessionName(),
            'chatId' => $chatId,
        ]));
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

        $message = $response->json('message');

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

        return is_array($body) ? $body : [];
    }
}
