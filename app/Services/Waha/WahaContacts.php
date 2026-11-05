<?php

namespace App\Services\Waha;

/** Consultas do WAHA sobre contatos do WhatsApp (separadas do `WahaClient`, que já tem métodos demais). */
class WahaContacts
{
    public function __construct(private readonly WahaClient $client) {}

    /** O número tem conta no WhatsApp? `$waId` como `5516999999999@c.us`; só os dígitos vão ao WAHA. */
    public function numberExists(string $waId): bool
    {
        $phone = (string) preg_replace('/\D/', '', explode('@', $waId)[0]);

        $response = $this->client->http()->get('/api/contacts/check-exists', ['phone' => $phone, 'session' => $this->client->sessionName()]);
        $this->client->check($response);

        return $response->json('numberExists') === true;
    }
}
