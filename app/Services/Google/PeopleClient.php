<?php

namespace App\Services\Google;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

/**
 * Cliente fino do People API (contatos). A escrita no Google é sequencial: quem chama garante um processo por vez.
 *
 * Repetição: 429 sempre (espera o `Retry-After` ou recua 1, 2, 4 s); 5xx e queda de rede só em leitura (GET), porque repetir
 * um POST que o Google pode ter aceitado duplicaria o contato.
 */
class PeopleClient
{
    private const BASE = 'https://people.googleapis.com/v1';

    private const READ_FIELDS = 'names,phoneNumbers,emailAddresses,metadata';

    private const MAX_ATTEMPTS = 4;

    // Pior caso de um envio: 6 chamadas de 10 s mais 40 s de espera cabem nos 110 s do job (`SyncContactToGoogleJob`).
    private const TIMEOUT_SECONDS = 10;

    private const MAX_TOTAL_WAIT_SECONDS = 40;

    private const MAX_BACKOFF_SECONDS = 30;

    private bool $warmedUp = false;

    private int $waitedSeconds = 0;

    public function __construct(private readonly string $accessToken) {}

    /**
     * A busca do Google é por prefixo e usa um cache preguiçoso: a primeira consulta de cada instância é vazia, só para aquecê-lo.
     *
     * @return list<GooglePerson>
     */
    public function searchContacts(string $query): array
    {
        if (! $this->warmedUp) {
            $this->warmedUp = true;
            $this->request('GET', '/people:searchContacts', ['query' => '', 'readMask' => self::READ_FIELDS, 'pageSize' => 10]);
        }

        $results = $this->request('GET', '/people:searchContacts', ['query' => $query, 'readMask' => self::READ_FIELDS, 'pageSize' => 10])->json('results');
        $found = [];

        foreach (is_array($results) ? $results : [] as $result) {
            if (is_array($result) && is_array($result['person'] ?? null)) {
                $found[] = GooglePerson::fromApi($result['person']);
            }
        }

        return $found;
    }

    public function getContact(string $resourceName): ?GooglePerson
    {
        $this->assertResourceName($resourceName);

        try {
            return GooglePerson::fromApi($this->request('GET', '/'.$resourceName, ['personFields' => self::READ_FIELDS])->json());
        } catch (GooglePeopleException $e) {
            if ($e->reason === 'not_found') {
                return null;
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $person
     */
    public function createContact(array $person): GooglePerson
    {
        return GooglePerson::fromApi($this->request('POST', '/people:createContact', ['personFields' => self::READ_FIELDS], $person)->json());
    }

    /**
     * Os campos da máscara são substituídos: um campo na máscara e ausente em `$person` é limpo no Google.
     *
     * @param  array<string, mixed>  $person
     * @param  list<string>  $fields
     */
    public function updateContact(GooglePerson $current, array $person, array $fields): GooglePerson
    {
        $this->assertResourceName($current->resourceName);

        // A fonte que se atualiza é identificada por `type` + `id` e versionada pelo `etag`; sem o `id` o Google responde 400.
        $source = array_filter(['type' => 'CONTACT', 'id' => $current->sourceId, 'etag' => $current->etag], fn (string $value): bool => $value !== '');
        $person['metadata'] = ['sources' => [$source]];

        $response = $this->request('PATCH', '/'.$current->resourceName.':updateContact', [
            'updatePersonFields' => implode(',', $fields),
            'personFields' => self::READ_FIELDS,
        ], $person);

        return GooglePerson::fromApi($response->json());
    }

    private function assertResourceName(string $resourceName): void
    {
        if (preg_match('#^people/[A-Za-z0-9_-]+$#', $resourceName) !== 1) {
            throw new GooglePeopleException('bad_request');
        }
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $body
     */
    private function request(string $method, string $path, array $query, ?array $body = null): Response
    {
        $isRead = $method === 'GET';

        for ($attempt = 1; ; $attempt++) {
            try {
                $response = Http::withToken($this->accessToken)->acceptJson()->timeout(self::TIMEOUT_SECONDS)
                    ->send($method, self::BASE.$path, array_filter(['query' => $query, 'json' => $body === null ? null : ($body === [] ? (object) [] : $body)], fn ($v) => $v !== null));
            } catch (ConnectionException) {
                if (! $isRead || $attempt >= self::MAX_ATTEMPTS) {
                    throw new GooglePeopleException('unavailable');
                }

                $this->backoff($attempt);

                continue;
            }

            if ($response->successful()) {
                return $response;
            }

            if ($this->shouldRetry($response, $isRead) && $attempt < self::MAX_ATTEMPTS) {
                $this->wait($response, $attempt);

                continue;
            }

            throw GooglePeopleException::fromResponse($response);
        }
    }

    private function shouldRetry(Response $response, bool $isRead): bool
    {
        return $response->status() === 429 || ($isRead && $response->serverError());
    }

    private function wait(Response $response, int $attempt): void
    {
        $retryAfter = $response->header('Retry-After');
        $rateLimited = $response->status() === 429;

        if ($rateLimited && ctype_digit($retryAfter) && (int) $retryAfter > 0) {
            $this->pause(min((int) $retryAfter, self::MAX_BACKOFF_SECONDS), 'rate_limited');

            return;
        }

        $this->backoff($attempt, $rateLimited ? 'rate_limited' : 'unavailable');
    }

    /** 1, 2, 4 s (no máximo 30) mais até 250 ms de variação, para duas filas não baterem juntas. */
    private function backoff(int $attempt, string $reason = 'unavailable'): void
    {
        $this->pause(min(self::MAX_BACKOFF_SECONDS, 2 ** ($attempt - 1)), $reason, random_int(0, 250));
    }

    /**
     * Espera, dentro de um orçamento por envio: o job tem tempo limite e, se estourar, o Laravel derruba o processo da fila.
     * Sem orçamento para esperar, desiste com o motivo da falha; a varredura reenvia depois.
     */
    private function pause(int $seconds, string $reason, int $jitterMs = 0): void
    {
        if ($this->waitedSeconds + $seconds > self::MAX_TOTAL_WAIT_SECONDS) {
            throw new GooglePeopleException($reason);
        }

        $this->waitedSeconds += $seconds;
        Sleep::for($seconds)->seconds()->and($jitterMs)->milliseconds();
    }
}
