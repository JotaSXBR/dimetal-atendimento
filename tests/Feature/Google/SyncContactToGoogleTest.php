<?php

namespace Tests\Feature\Google;

use App\Enums\GoogleConnectionStatus;
use App\Models\Contact;
use App\Models\GoogleConnection;
use App\Services\Google\SyncContactToGoogle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class SyncContactToGoogleTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '+5516997524950';

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        config(['services.google' => ['client_id' => 'cid', 'client_secret' => 'secret', 'redirect' => 'http://localhost/cb']]);
    }

    private function connect(GoogleConnectionStatus $status = GoogleConnectionStatus::CONNECTED): void
    {
        GoogleConnection::query()->create([
            'account_email' => 'dimetal@gmail.com', 'access_token' => 'tok', 'refresh_token' => 'ref',
            'expires_at' => now()->addHour(), 'status' => $status, 'connected_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function person(string $name, string $etag, string $phone = self::PHONE, string $email = ''): array
    {
        return [
            'resourceName' => $name,
            'metadata' => ['sources' => [['type' => 'CONTACT', 'etag' => $etag]]],
            'phoneNumbers' => [['value' => $phone, 'canonicalForm' => $phone]],
            'emailAddresses' => $email === '' ? [] : [['value' => $email]],
        ];
    }

    /**
     * @param  array<string, mixed>  $options  search (consulta => pessoas), create, get, patch (fila de respostas)
     */
    private function fakeGoogle(array $options = []): void
    {
        $patch = $options['patch'] ?? [Http::response($this->person('people/c1', 'E2'), 200)];

        Http::fake(function (Request $request) use (&$patch, $options) {
            $url = $request->url();

            if (str_contains($url, ':searchContacts')) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $people = $options['search'][$query['query']] ?? [];

                return Http::response(['results' => array_map(fn (array $person): array => ['person' => $person], $people)], 200);
            }

            if (str_contains($url, ':createContact')) {
                return $options['create'] ?? Http::response($this->person('people/c9', 'E9'), 200);
            }

            if (str_contains($url, ':updateContact')) {
                return array_shift($patch) ?? Http::response('', 500);
            }

            return $options['get'] ?? Http::response($this->person('people/c1', 'E1'), 200);
        });
    }

    private function contact(array $attributes = []): Contact
    {
        return Contact::factory()->edited()->create(array_replace(['wa_id' => '5516997524950@c.us', 'name' => 'Maria Silva'], $attributes));
    }

    private function sync(Contact $contact): string
    {
        return app(SyncContactToGoogle::class)($contact);
    }

    /** @return list<Request> */
    private function sent(string $needle, ?string $method = null): array
    {
        return Http::recorded(fn (Request $r) => str_contains($r->url(), $needle) && ($method === null || $r->method() === $method))
            ->map(fn (array $pair): Request => $pair[0])->values()->all();
    }

    public function test_pula_sem_conexao_sem_edicao_ou_sem_como_ser_achado(): void
    {
        Http::fake();
        $edited = $this->contact();
        $this->assertSame('skipped', $this->sync($edited), 'sem conexão');

        $this->connect();
        $this->assertSame('skipped', $this->sync(Contact::factory()->create()), 'nunca editado');
        $this->assertSame('skipped', $this->sync($this->contact(['wa_id' => null, 'lid' => '1@lid'])), 'só LID e sem e-mail');

        Http::assertNothingSent();
        $this->assertNull($edited->fresh()?->google_attempted_at);
    }

    public function test_cria_quando_a_busca_nao_acha_buscando_por_telefone_e_por_email(): void
    {
        $this->connect();
        $this->fakeGoogle();
        $contact = $this->contact(['email' => 'maria@silva.com.br']);

        $this->assertSame('created', $this->sync($contact));

        $queries = array_map(function (Request $r): string {
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

            return (string) $q['query'];
        }, $this->sent(':searchContacts'));
        $this->assertSame(['', self::PHONE, 'maria@silva.com.br'], $queries);
        $this->assertCount(1, $this->sent(':createContact'));
        $fresh = $contact->fresh();
        $this->assertSame('people/c9', $fresh?->google_resource_name);
        $this->assertSame('E9', $fresh?->google_etag);
        $this->assertNotNull($fresh?->google_synced_at);
        $this->assertNotNull($fresh?->google_attempted_at);
        $this->assertNull($fresh?->google_error);
        $this->assertSame(64, strlen((string) $fresh?->google_payload_hash));
    }

    public function test_prefixo_nao_e_igualdade(): void
    {
        $this->connect();
        $this->fakeGoogle(['search' => [self::PHONE => [$this->person('people/c7', 'E7', '+55169975249501')]]]);

        $this->assertSame('created', $this->sync($this->contact()));
        $this->assertCount(0, $this->sent(':updateContact'));
    }

    public function test_adota_o_contato_existente_atualizando_so_o_que_o_app_tem(): void
    {
        $this->connect();
        $this->fakeGoogle(['search' => [self::PHONE => [$this->person('people/c1', 'E1')]]]);
        $contact = $this->contact();

        $this->assertSame('updated', $this->sync($contact));

        $this->assertCount(0, $this->sent(':createContact'));
        $patch = $this->sent(':updateContact', 'PATCH')[0];
        parse_str((string) parse_url($patch->url(), PHP_URL_QUERY), $query);
        $this->assertSame('names,phoneNumbers', $query['updatePersonFields'], 'sem organizations, e-mail etc.: o app não tem, o Google mantém');
        $this->assertSame('people/c1', $contact->fresh()?->google_resource_name);
    }

    public function test_busca_sem_etag_rele_o_contato_antes_de_atualizar(): void
    {
        $this->connect();
        $semEtag = ['resourceName' => 'people/c5', 'phoneNumbers' => [['value' => self::PHONE, 'canonicalForm' => self::PHONE]]];
        $this->fakeGoogle([
            'search' => [self::PHONE => [$semEtag]],
            'get' => Http::response($this->person('people/c5', 'E5'), 200),
        ]);
        $contact = $this->contact();

        $this->assertSame('updated', $this->sync($contact));

        $patch = $this->sent(':updateContact', 'PATCH')[0];
        $this->assertSame('E5', $patch->data()['metadata']['sources'][0]['etag'], 'o etag vem da releitura, nunca vazio');
        $this->assertNull($contact->fresh()?->google_error);
    }

    public function test_sem_etag_nem_depois_da_releitura_falha_sem_chamar_o_patch_e_explica_no_log(): void
    {
        Log::spy();
        $this->connect();
        $semEtag = [
            'resourceName' => 'people/c5',
            'metadata' => ['sources' => [['type' => 'PROFILE']]],
            'phoneNumbers' => [['value' => self::PHONE, 'canonicalForm' => self::PHONE]],
        ];
        $this->fakeGoogle(['search' => [self::PHONE => [$semEtag]], 'get' => Http::response($semEtag, 200)]);
        $contact = $this->contact();

        $this->assertSame('failed', $this->sync($contact));

        $this->assertCount(0, $this->sent(':updateContact'));
        $this->assertSame('invalid_response', $contact->fresh()?->google_error);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => str_contains((string) $context['detail'], 'people/c5')
            && str_contains((string) $context['detail'], 'PROFILE'));
    }

    public function test_contato_ja_espelhado_usa_a_mascara_completa_e_limpa_o_campo_esvaziado(): void
    {
        $this->connect();
        $this->fakeGoogle();
        $contact = $this->contact(['google_resource_name' => 'people/c1', 'google_payload_hash' => 'hash-antigo', 'company' => null]);

        $this->assertSame('updated', $this->sync($contact));

        $patch = $this->sent(':updateContact', 'PATCH')[0];
        parse_str((string) parse_url($patch->url(), PHP_URL_QUERY), $query);
        $this->assertSame('names,phoneNumbers,emailAddresses,organizations,addresses,biographies,birthdays,urls', $query['updatePersonFields']);
        $this->assertArrayNotHasKey('organizations', $patch->data());
        $this->assertSame('E2', $contact->fresh()?->google_etag);
    }

    public function test_mesmo_hash_e_resource_name_nao_faz_nenhuma_chamada(): void
    {
        $this->connect();
        $this->fakeGoogle();
        $contact = $this->contact(['google_resource_name' => 'people/c1']);
        $this->sync($contact);
        Http::fake();
        $again = $contact->fresh();

        $this->assertSame('unchanged', $this->sync($again));

        Http::assertNothingSent();
    }

    public function test_etag_velho_rele_e_tenta_uma_vez(): void
    {
        $this->connect();
        $conflict = Http::response(['error' => ['code' => 400, 'status' => 'FAILED_PRECONDITION']], 400);
        $this->fakeGoogle(['patch' => [$conflict, Http::response($this->person('people/c1', 'E3'), 200)]]);
        $contact = $this->contact(['google_resource_name' => 'people/c1', 'google_payload_hash' => 'antigo']);

        $this->assertSame('updated', $this->sync($contact));

        $this->assertCount(2, $this->sent(':updateContact'));
        $this->assertCount(2, $this->sent('/people/c1', 'GET'));
        $this->assertSame('E3', $contact->fresh()?->google_etag);
    }

    public function test_conflito_que_persiste_vira_falha_conflict(): void
    {
        $this->connect();
        $conflict = Http::response(['error' => ['code' => 400, 'status' => 'FAILED_PRECONDITION']], 400);
        $this->fakeGoogle(['patch' => [$conflict, $conflict, $conflict]]);
        $contact = $this->contact(['google_resource_name' => 'people/c1', 'google_payload_hash' => 'antigo']);

        $this->assertSame('failed', $this->sync($contact));

        $this->assertCount(2, $this->sent(':updateContact'), 'uma nova tentativa só');
        $this->assertSame('conflict', $contact->fresh()?->google_error);
    }

    public function test_contato_apagado_no_google_e_recriado(): void
    {
        $this->connect();
        $this->fakeGoogle(['get' => Http::response(['error' => ['code' => 404, 'status' => 'NOT_FOUND']], 404)]);
        $contact = $this->contact(['google_resource_name' => 'people/c1', 'google_payload_hash' => 'antigo']);

        $this->assertSame('created', $this->sync($contact));

        $this->assertSame('people/c9', $contact->fresh()?->google_resource_name);
    }

    public function test_401_renova_o_token_uma_vez_e_conclui_o_envio(): void
    {
        $this->connect();
        $unauthorized = Http::response(['error' => ['code' => 401]], 401);
        $created = $this->person('people/c9', 'E9');
        $calls = 0;
        Http::fake(function (Request $request) use ($unauthorized, $created, &$calls) {
            if (str_contains($request->url(), 'oauth2.googleapis.com')) {
                return Http::response(['access_token' => 'novo', 'expires_in' => 3600], 200);
            }

            if (str_contains($request->url(), ':createContact')) {
                return ++$calls === 1 ? $unauthorized : Http::response($created, 200);
            }

            return Http::response([], 200);
        });
        $contact = $this->contact();

        $this->assertSame('created', $this->sync($contact));

        $this->assertSame(GoogleConnectionStatus::CONNECTED, GoogleConnection::query()->firstOrFail()->status);
        $this->assertSame('novo', GoogleConnection::query()->firstOrFail()->access_token);
    }

    public function test_401_que_persiste_depois_de_renovar_pede_nova_autorizacao(): void
    {
        $this->connect();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'oauth2.googleapis.com')) {
                return Http::response(['access_token' => 'novo', 'expires_in' => 3600], 200);
            }

            return str_contains($request->url(), ':createContact') ? Http::response(['error' => ['code' => 401]], 401) : Http::response([], 200);
        });
        $contact = $this->contact();

        $this->assertSame('failed', $this->sync($contact));

        $this->assertSame('unauthorized', $contact->fresh()?->google_error);
        $this->assertSame(GoogleConnectionStatus::NEEDS_REAUTH, GoogleConnection::query()->firstOrFail()->status);
    }

    public function test_401_com_a_autorizacao_revogada_pede_nova_autorizacao(): void
    {
        $this->connect();
        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'oauth2.googleapis.com')) {
                return Http::response(['error' => 'invalid_grant'], 400);
            }

            return str_contains($request->url(), ':createContact') ? Http::response(['error' => ['code' => 401]], 401) : Http::response([], 200);
        });

        $this->assertSame('failed', $this->sync($this->contact()));

        $this->assertSame(GoogleConnectionStatus::NEEDS_REAUTH, GoogleConnection::query()->firstOrFail()->status);
    }

    public function test_403_comum_de_um_contato_nao_desconecta_a_conta(): void
    {
        $this->connect();
        $this->fakeGoogle(['create' => Http::response(['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED']], 403)]);
        $contact = $this->contact();

        $this->assertSame('failed', $this->sync($contact));

        $this->assertSame('forbidden', $contact->fresh()?->google_error);
        $this->assertNotNull($contact->fresh()?->google_attempted_at);
        $this->assertSame(GoogleConnectionStatus::CONNECTED, GoogleConnection::query()->firstOrFail()->status);
    }

    public function test_403_por_escopo_insuficiente_pede_nova_autorizacao(): void
    {
        $this->connect();
        $this->fakeGoogle(['create' => Http::response(['error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'details' => [['reason' => 'ACCESS_TOKEN_SCOPE_INSUFFICIENT']]]], 403)]);
        $contact = $this->contact();

        $this->assertSame('failed', $this->sync($contact));

        $this->assertSame('insufficient_scope', $contact->fresh()?->google_error);
        $this->assertSame(GoogleConnectionStatus::NEEDS_REAUTH, GoogleConnection::query()->firstOrFail()->status);
    }

    public function test_api_desligada_nao_derruba_a_conexao(): void
    {
        $this->connect();
        $this->fakeGoogle(['create' => Http::response(['error' => ['code' => 403, 'details' => [['reason' => 'SERVICE_DISABLED']]]], 403)]);
        $contact = $this->contact();

        $this->assertSame('failed', $this->sync($contact));

        $this->assertSame('api_disabled', $contact->fresh()?->google_error);
        $this->assertSame(GoogleConnectionStatus::CONNECTED, GoogleConnection::query()->firstOrFail()->status);
    }

    public function test_429_persistente_vira_falha_rate_limited(): void
    {
        $this->connect();
        $this->fakeGoogle(['create' => Http::response('', 429)]);
        $contact = $this->contact();

        $this->assertSame('failed', $this->sync($contact));

        $this->assertSame('rate_limited', $contact->fresh()?->google_error);
    }

    public function test_falha_do_google_vai_para_o_log_com_o_motivo_que_ele_devolveu(): void
    {
        Log::spy();
        $this->connect();
        $this->fakeGoogle(['create' => Http::response(['error' => [
            'code' => 400,
            'status' => 'INVALID_ARGUMENT',
            'message' => "Invalid value at 'person.birthdays[0]'",
            'details' => [['fieldViolations' => [['field' => 'person.birthdays[0].date', 'description' => 'invalid date']]]],
        ]], 400)]);
        $contact = $this->contact();

        $this->assertSame('failed', $this->sync($contact));

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context['contact'] === $contact->id
            && $context['reason'] === 'bad_request'
            && $context['http'] === 400
            && str_contains((string) $context['detail'], 'person.birthdays[0].date (invalid date)'));
        $this->assertSame('bad_request', $contact->fresh()?->google_error);
    }

    public function test_falha_sem_resposta_do_google_loga_so_o_codigo(): void
    {
        Log::spy();
        $this->connect();
        $this->fakeGoogle(['create' => Http::response('', 500)]);
        $contact = $this->contact();

        $this->assertSame('failed', $this->sync($contact));

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context['reason'] === 'unavailable'
            && $context['http'] === 500
            && $context['detail'] === null);
    }

    public function test_sucesso_limpa_o_erro_anterior(): void
    {
        $this->connect();
        $this->fakeGoogle();
        $contact = $this->contact(['google_error' => 'rate_limited']);

        $this->sync($contact);

        $this->assertNull($contact->fresh()?->google_error);
    }

    public function test_sem_token_utilizavel_falha_sem_chamar_o_google(): void
    {
        $this->connect(GoogleConnectionStatus::NEEDS_REAUTH);
        Http::fake();
        $contact = $this->contact();

        $this->assertSame('skipped', $this->sync($contact));

        Http::assertNothingSent();
    }
}
