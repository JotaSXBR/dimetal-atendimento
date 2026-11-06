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

    /** O telefone (`5516999999999@c.us`) por trás de um `@lid`; `null` quando o WAHA não o conhece. */
    public function phoneForLid(string $lid): ?string
    {
        $response = $this->client->http()->get('/api/'.$this->client->sessionName().'/lids/'.rawurlencode($lid));

        if ($response->status() === 404) {
            return null;
        }

        $this->client->check($response);
        $phone = $response->json('pn');

        return is_string($phone) && $phone !== '' ? $phone : null;
    }

    /**
     * Os nomes que o WhatsApp tem para o contato: `agenda` (salvo no celular da empresa) e `push` (o que o cliente escolheu).
     * `$id` é `…@c.us` ou `…@lid`. Contato desconhecido (404) ou campo vazio vira `null`.
     *
     * @return array{agenda: ?string, push: ?string}
     */
    public function contactNames(string $id): array
    {
        $response = $this->client->http()->get('/api/contacts', ['contactId' => $id, 'session' => $this->client->sessionName()]);

        if ($response->status() === 404) {
            return ['agenda' => null, 'push' => null];
        }

        $this->client->check($response);
        $clean = fn (mixed $name): ?string => is_string($name) && trim($name) !== '' ? trim($name) : null;

        return ['agenda' => $clean($response->json('name')), 'push' => $clean($response->json('pushname'))];
    }
}
