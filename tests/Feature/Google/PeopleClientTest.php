<?php

namespace Tests\Feature\Google;

use App\Services\Google\GooglePeopleException;
use App\Services\Google\GooglePerson;
use App\Services\Google\PeopleClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class PeopleClientTest extends TestCase
{
    private const BASE = 'https://people.googleapis.com/v1';

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    private function client(): PeopleClient
    {
        return new PeopleClient('tok-123');
    }

    /** @return array<string, mixed> */
    private function person(string $name = 'people/c1', string $etag = 'E1'): array
    {
        return [
            'resourceName' => $name,
            'etag' => 'top-'.$etag,
            'metadata' => ['sources' => [['type' => 'CONTACT', 'id' => 'c1', 'etag' => $etag]]],
            'phoneNumbers' => [['value' => '+55 16 99752-4950', 'canonicalForm' => '+5516997524950']],
            'emailAddresses' => [['value' => 'a@b.com']],
        ];
    }

    private function failsWith(string $code, callable $call): void
    {
        try {
            $call();
            $this->fail('Deveria lançar GooglePeopleException('.$code.').');
        } catch (GooglePeopleException $e) {
            $this->assertSame($code, $e->reason);
        }
    }

    public function test_create_contact_envia_post_com_bearer_e_devolve_a_pessoa(): void
    {
        Http::fake([self::BASE.'/people:createContact*' => Http::response($this->person(), 200)]);

        $created = $this->client()->createContact(['names' => [['unstructuredName' => 'Maria']]]);

        $this->assertEquals(new GooglePerson('people/c1', 'E1', ['+5516997524950'], ['a@b.com']), $created);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST'
            && str_starts_with($r->url(), self::BASE.'/people:createContact')
            && $r->hasHeader('Authorization', 'Bearer tok-123')
            && $r['names'][0]['unstructuredName'] === 'Maria');
    }

    public function test_update_contact_usa_patch_com_mascara_e_etag(): void
    {
        Http::fake(['*' => Http::response($this->person('people/c1', 'E2'), 200)]);
        $current = new GooglePerson('people/c1', 'E1', [], []);

        $updated = $this->client()->updateContact($current, ['names' => [['unstructuredName' => 'Maria']]], ['names', 'phoneNumbers']);

        $this->assertSame('E2', $updated->etag);
        Http::assertSent(function (Request $r) {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $query);

            return $r->method() === 'PATCH'
                && str_starts_with($r->url(), self::BASE.'/people/c1:updateContact')
                && $query['updatePersonFields'] === 'names,phoneNumbers'
                && $r['metadata']['sources'][0]['etag'] === 'E1';
        });
    }

    public function test_get_contact_devolve_null_quando_nao_existe(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 404, 'status' => 'NOT_FOUND']], 404)]);

        $this->assertNull($this->client()->getContact('people/c999'));
    }

    public function test_get_contact_le_a_pessoa(): void
    {
        Http::fake(['*' => Http::response($this->person(), 200)]);

        $this->assertSame('people/c1', $this->client()->getContact('people/c1')?->resourceName);
    }

    public function test_search_contacts_aquece_uma_vez_e_devolve_os_resultados(): void
    {
        Http::fake(['*' => Http::response(['results' => [['person' => $this->person()]]], 200)]);
        $client = $this->client();

        $first = $client->searchContacts('+5516997524950');
        $client->searchContacts('a@b.com');

        $this->assertSame('people/c1', $first[0]->resourceName);
        $queries = Http::recorded()->map(function (array $pair): string {
            parse_str((string) parse_url($pair[0]->url(), PHP_URL_QUERY), $query);

            return (string) ($query['query'] ?? '?');
        })->all();
        $this->assertSame(['', '+5516997524950', 'a@b.com'], $queries, 'aquecimento com query vazia, uma única vez por instância');
    }

    public function test_search_sem_resultado_devolve_lista_vazia(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->assertSame([], $this->client()->searchContacts('x'));
    }

    public function test_etag_velho_vira_conflict(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 400, 'status' => 'FAILED_PRECONDITION', 'message' => 'etag']], 400)]);

        $this->failsWith('conflict', fn () => $this->client()->updateContact(new GooglePerson('people/c1', 'E1', [], []), [], ['names']));
    }

    public function test_outros_400_viram_bad_request(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT']], 400)]);

        $this->failsWith('bad_request', fn () => $this->client()->createContact([]));
    }

    public function test_401_vira_unauthorized(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 401, 'status' => 'UNAUTHENTICATED']], 401)]);

        $this->failsWith('unauthorized', fn () => $this->client()->createContact([]));
    }

    public function test_403_com_servico_desligado_vira_api_disabled(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'details' => [['reason' => 'SERVICE_DISABLED']]]], 403)]);

        $this->failsWith('api_disabled', fn () => $this->client()->createContact([]));
    }

    public function test_403_comum_vira_forbidden(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED']], 403)]);

        $this->failsWith('forbidden', fn () => $this->client()->createContact([]));
    }

    public function test_429_com_retry_after_espera_e_repete(): void
    {
        Http::fake(['*' => Http::sequence()->push('', 429, ['Retry-After' => '2'])->push($this->person(), 200)]);

        $created = $this->client()->createContact([]);

        $this->assertSame('people/c1', $created->resourceName);
        Sleep::assertSequence([Sleep::for(2)->seconds()]);
        Http::assertSentCount(2);
    }

    public function test_429_persistente_desiste_depois_de_4_tentativas(): void
    {
        Http::fake(['*' => Http::response('', 429)]);

        $this->failsWith('rate_limited', fn () => $this->client()->createContact([]));

        Http::assertSentCount(4);
    }

    public function test_esperas_longas_estouram_o_orcamento_e_desistem_antes_do_timeout_do_job(): void
    {
        Http::fake(['*' => Http::response('', 429, ['Retry-After' => '30'])]);

        $this->failsWith('rate_limited', fn () => $this->client()->createContact([]));

        Http::assertSentCount(2);
        Sleep::assertSleptTimes(1);
    }

    public function test_503_em_get_repete(): void
    {
        Http::fake(['*' => Http::sequence()->push('', 503)->push($this->person(), 200)]);

        $this->assertSame('people/c1', $this->client()->getContact('people/c1')?->resourceName);
        Http::assertSentCount(2);
    }

    public function test_503_em_post_nao_repete(): void
    {
        Http::fake(['*' => Http::response('', 503)]);

        $this->failsWith('unavailable', fn () => $this->client()->createContact([]));

        Http::assertSentCount(1);
    }

    public function test_resposta_sem_resource_name_e_invalida(): void
    {
        Http::fake(['*' => Http::response(['foo' => 'bar'], 200)]);

        $this->failsWith('invalid_response', fn () => $this->client()->createContact([]));
    }

    public function test_resource_name_fora_do_padrao_nao_chama_a_rede(): void
    {
        Http::fake();

        $this->failsWith('bad_request', fn () => $this->client()->getContact('people/../me'));
        $this->failsWith('bad_request', fn () => $this->client()->updateContact(new GooglePerson('otherContacts/c1', 'E', [], []), [], ['names']));

        Http::assertNothingSent();
    }
}
