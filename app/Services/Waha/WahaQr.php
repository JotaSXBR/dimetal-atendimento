<?php

namespace App\Services\Waha;

/**
 * O QR de pareamento da sessão: o texto cru (o navegador o desenha com a logo) e a imagem do WAHA como reserva.
 */
class WahaQr
{
    public function __construct(private readonly WahaClient $client) {}

    /** O texto do QR, ou `null` se o WAHA não entregar. */
    public function raw(): ?string
    {
        $response = $this->client->http()->get($this->path(), ['format' => 'raw']);

        if ($response->failed()) {
            return null;
        }

        $value = $response->json('value');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function dataUri(): ?string
    {
        $response = $this->client->http()
            ->withHeaders(['Accept' => 'image/png'])
            ->get($this->path(), ['format' => 'image']);

        if ($response->failed() || ! str_starts_with((string) $response->header('Content-Type'), 'image/')) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode($response->body());
    }

    private function path(): string
    {
        return '/api/'.$this->client->sessionName().'/auth/qr';
    }
}
