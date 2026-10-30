<?php

namespace App\Services\Google;

use App\Models\Contact;

/**
 * Espelha um contato editado no Google Contatos (só de ida). Devolve `created`, `updated`, `unchanged`, `skipped` ou `failed`
 * e nunca lança erro do Google: a falha fica em `contacts.google_error`, para a ficha mostrar e a rotina reenviar.
 *
 * Contato que já existe no Google (mesmo telefone ou e-mail) é adotado, nunca duplicado, e nesse primeiro envio só vão os
 * campos que o app tem. Depois, o contato já espelhado leva a máscara completa e o campo esvaziado no app é limpo lá.
 */
class SyncContactToGoogle
{
    public function __construct(private readonly GoogleAccount $account, private readonly ContactToPerson $mapper) {}

    public function __invoke(Contact $contact): string
    {
        if (! $this->account->isConnected() || ! $this->mapper->isEligible($contact)) {
            return 'skipped';
        }

        $person = $this->mapper->map($contact);
        $hash = $this->mapper->hash($person);

        if ($contact->google_resource_name !== null && $contact->google_payload_hash === $hash) {
            $this->settle($contact, []);

            return 'unchanged';
        }

        $token = $this->account->accessToken();
        $contact->forceFill(['google_attempted_at' => now()])->save();

        if ($token === null) {
            return $this->fail($contact, $this->account->current()->last_error ?? 'token_unavailable');
        }

        try {
            [$result, $google] = $this->push(new PeopleClient($token), $contact, $person);
        } catch (GooglePeopleException $e) {
            return $this->fail($contact, $e->reason);
        }

        $this->settle($contact, [
            'google_resource_name' => $google->resourceName,
            'google_etag' => $google->etag,
            'google_payload_hash' => $hash,
        ]);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $person
     * @return array{string, GooglePerson}
     */
    private function push(PeopleClient $client, Contact $contact, array $person): array
    {
        $known = $contact->google_resource_name === null ? null : $client->getContact($contact->google_resource_name);

        if ($known !== null) {
            return ['updated', $this->update($client, $known, $person, $this->mapper->fields())];
        }

        $existing = $this->findExisting($client, $contact);

        if ($existing !== null) {
            return ['updated', $this->update($client, $existing, $person, array_keys($person))];
        }

        return ['created', $client->createContact($person)];
    }

    /**
     * `etag` velho (alguém mexeu no contato no Google): relê e tenta uma vez.
     *
     * @param  array<string, mixed>  $person
     * @param  list<string>  $fields
     */
    private function update(PeopleClient $client, GooglePerson $current, array $person, array $fields): GooglePerson
    {
        try {
            return $client->updateContact($current, $person, $fields);
        } catch (GooglePeopleException $e) {
            $fresh = $e->reason === 'conflict' ? $client->getContact($current->resourceName) : null;

            if ($fresh === null) {
                throw $e;
            }

            return $client->updateContact($fresh, $person, $fields);
        }
    }

    /** A busca do Google é por prefixo: só vale quem tem exatamente o mesmo telefone (dígitos) ou o mesmo e-mail. */
    private function findExisting(PeopleClient $client, Contact $contact): ?GooglePerson
    {
        $digits = str_ends_with((string) $contact->wa_id, '@c.us') ? preg_replace('/\D/', '', (string) $contact->wa_id) : null;

        if ($digits !== null) {
            foreach ($client->searchContacts('+'.$digits) as $candidate) {
                if (in_array($digits, array_map(fn (string $phone): ?string => preg_replace('/\D/', '', $phone), $candidate->phones), true)) {
                    return $candidate;
                }
            }
        }

        $email = strtolower(trim((string) $contact->email));

        if ($email === '') {
            return null;
        }

        foreach ($client->searchContacts($email) as $candidate) {
            if (in_array($email, array_map(strtolower(...), $candidate->emails), true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Marca o contato como em dia. `google_synced_at` e `updated_at` recebem o mesmo instante: é assim que a varredura
     * sabe que nada mudou desde o envio (`Contact::scopePendingGoogle`).
     *
     * @param  array<string, mixed>  $mirror
     */
    private function settle(Contact $contact, array $mirror): void
    {
        $now = now();

        $contact->forceFill($mirror + ['google_synced_at' => $now, 'google_error' => null, 'updated_at' => $now])->save();
    }

    private function fail(Contact $contact, string $reason): string
    {
        $contact->forceFill(['google_error' => $reason])->save();

        // Só uma nova autorização resolve acesso recusado; os demais erros a rotina reenvia depois.
        if (in_array($reason, ['unauthorized', 'forbidden'], true)) {
            $this->account->markNeedsReauth($reason);
        }

        return 'failed';
    }
}
