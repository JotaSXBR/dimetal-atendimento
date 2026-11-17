<?php

namespace Tests\Feature\Update;

use App\Services\Update\Coolify;
use App\Services\Update\CoolifyException;
use App\Settings\UpdateSettings;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CoolifyTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://painel.example/api/v1';

    private const TOKEN = '7|segredo-do-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['fluxie.resource_uuid' => 'svc123']);
        UpdateSettings::setPanelUrl('https://painel.example/');
        UpdateSettings::setToken(self::TOKEN);
    }

    private function fx(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/coolify/{$name}.json"));
    }

    private function raw(string $fixture, int $status = 200): PromiseInterface
    {
        return Http::response($this->fx($fixture), $status, ['Content-Type' => 'application/json']);
    }

    private function fails(callable $call, int $status, string $contains): CoolifyException
    {
        try {
            $call();
        } catch (CoolifyException $e) {
            $this->assertSame($status, $e->status);
            $this->assertStringContainsString($contains, $e->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, $e->getMessage());
            $this->assertStringNotContainsString(self::TOKEN, (string) $e);

            return $e;
        }
        $this->fail('Esperava CoolifyException.');
    }

    public function test_compose_devolve_o_texto_e_manda_cabecalhos_e_url_certos(): void
    {
        Http::fake([self::BASE.'/services/svc123' => $this->raw('service-ok')]);

        $this->assertStringContainsString('ghcr.io/fluxie-tech/fluxie-inbox', app(Coolify::class)->compose());

        Http::assertSent(fn (Request $r) => $r->method() === 'GET'
            && $r->url() === self::BASE.'/services/svc123'
            && $r->hasHeader('Authorization', 'Bearer '.self::TOKEN)
            && $r->hasHeader('Accept', 'application/json'));
    }

    /** @return array<string, array{string}> */
    public static function urlsDoPainel(): array
    {
        return ['sem barra' => ['https://painel.example'], 'com barra' => ['https://painel.example/']];
    }

    #[DataProvider('urlsDoPainel')]
    public function test_url_do_painel_com_ou_sem_barra_monta_a_mesma_url(string $panel): void
    {
        UpdateSettings::setPanelUrl($panel);
        Http::fake(['*' => $this->raw('service-ok')]);

        app(Coolify::class)->compose();

        Http::assertSent(fn (Request $r) => $r->url() === 'https://painel.example/api/v1/services/svc123');
    }

    public function test_compose_sem_docker_compose_raw_falha(): void
    {
        Http::fake(['*' => $this->raw('service-sem-compose')]);

        $this->fails(fn () => app(Coolify::class)->compose(), 200, 'compose');
    }

    public function test_envs_ignora_preview_e_distingue_vazio_nulo_e_ausente(): void
    {
        Http::fake([self::BASE.'/services/svc123/envs' => $this->raw('envs-ok')]);

        $envs = app(Coolify::class)->envs();

        $this->assertSame(['FLUXIE_VERSION' => '', 'APP_URL' => 'https://x.example', 'NULO' => null], $envs);
        $this->assertTrue(array_key_exists('NULO', $envs));
        $this->assertFalse(array_key_exists('OUTRA', $envs));
    }

    public function test_set_env_faz_patch_e_cai_em_post_no_404(): void
    {
        Http::fake([
            self::BASE.'/services/svc123/envs' => Http::sequence()->push($this->fx('coolify-patch-env-full-404'), 404)->push($this->fx('env-created-201'), 201),
        ]);

        app(Coolify::class)->setEnv('FLUXIE_VERSION', '0.2.0');

        $body = ['key' => 'FLUXIE_VERSION', 'value' => '0.2.0', 'is_preview' => false];
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === $body);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data() === $body);
    }

    public function test_set_env_so_com_patch_quando_existe(): void
    {
        Http::fake(['*' => Http::response('{}', 201)]);

        app(Coolify::class)->setEnv('A', 'b');

        Http::assertSentCount(1);
    }

    public function test_set_env_409_no_post_vira_excecao(): void
    {
        Http::fake([self::BASE.'/services/svc123/envs' => Http::sequence()->push($this->fx('coolify-patch-env-full-404'), 404)->push($this->fx('env-409'), 409)]);

        $this->fails(fn () => app(Coolify::class)->setEnv('A', 'b'), 409, 'já existe');
    }

    public function test_put_compose_manda_base64(): void
    {
        Http::fake(['*' => Http::response('{"message":"ok"}', 200)]);

        app(Coolify::class)->putCompose("services:\n  app: {}\n");

        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH'
            && $r->url() === self::BASE.'/services/svc123'
            && $r->data() === ['docker_compose_raw' => base64_encode("services:\n  app: {}\n")]);
    }

    public function test_restart_com_e_sem_pull(): void
    {
        Http::fake(['*' => $this->raw('restart-ok')]);

        app(Coolify::class)->restart(true);
        app(Coolify::class)->restart(false);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === self::BASE.'/services/svc123/restart?latest=true');
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === self::BASE.'/services/svc123/restart');
    }

    public function test_nunca_envia_delete(): void
    {
        Http::fake(['*' => Http::response('{}', 200)]);
        $c = app(Coolify::class);

        $c->setEnv('A', 'b');
        $c->putCompose('x');
        $c->restart(true);

        Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
    }

    public function test_can_deploy_404_sim_403_nao_outro_excecao(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push($this->fx('coolify-restart-full-404'), 404)
            ->push($this->fx('coolify-restart-nodeploy-403'), 403)
            ->push($this->fx('erro-500'), 500), ]);
        $c = app(Coolify::class);

        $this->assertTrue($c->canDeploy());
        $this->assertFalse($c->canDeploy());
        $this->fails(fn () => $c->canDeploy(), 500, '500');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/services/00000000-nao-existe-0000/restart'));
    }

    /** @return array<string, array{int, string, string}> */
    public static function erros(): array
    {
        return [
            '401' => [401, 'coolify-bad-token-401', 'token inválido'],
            '403 write' => [403, 'coolify-patch-env-read-403', 'token não tem a permissão write'],
            '403 deploy' => [403, 'coolify-restart-nodeploy-403', 'token não tem a permissão deploy'],
            '404' => [404, 'coolify-get-service-404', 'não encontrado'],
            '409' => [409, 'env-409', 'já existe'],
            '500' => [500, 'erro-500', 'erro interno (HTTP 500)'],
            '503' => [503, 'erro-500', 'erro interno (HTTP 503)'],
            '418' => [418, 'erro-500', 'erro (HTTP 418)'],
        ];
    }

    #[DataProvider('erros')]
    public function test_status_vira_mensagem_em_portugues_sem_token(int $status, string $fixture, string $contains): void
    {
        Http::fake(['*' => $this->raw($fixture, $status)]);
        $c = app(Coolify::class);

        foreach ([
            fn () => $c->compose(),
            fn () => $c->envs(),
            fn () => $c->setEnv('A', 'b'),
            fn () => $c->putCompose('x'),
            fn () => $c->restart(true),
        ] as $call) {
            $this->fails($call, $status, $contains);
        }
    }

    public function test_404_reais_do_coolify_viram_nao_encontrado(): void
    {
        $c = app(Coolify::class);

        Http::fake(['*' => $this->raw('coolify-envs-read-on-missing-404', 404)]);
        $this->fails(fn () => $c->envs(), 404, 'não encontrado');

        Http::fake(['*' => $this->raw('coolify-patch-service-full-404', 404)]);
        $this->fails(fn () => $c->putCompose('x'), 404, 'não encontrado');

        Http::fake(['*' => Http::sequence()->push($this->fx('coolify-patch-env-full-404'), 404)->push($this->fx('coolify-post-env-full-404'), 404)]);
        $this->fails(fn () => $c->setEnv('A', 'b'), 404, 'não encontrado');
    }

    public function test_is_preview_so_e_preview_quando_verdadeiro(): void
    {
        Http::fake(['*' => Http::response([
            ['key' => 'A', 'value' => '1', 'is_preview' => 0],
            ['key' => 'B', 'value' => '2', 'is_preview' => '0'],
            ['key' => 'C', 'value' => '3', 'is_preview' => null],
            ['key' => 'D', 'value' => '4'],
            ['key' => 'E', 'value' => '5', 'is_preview' => 1],
            ['key' => 'F', 'value' => '6', 'is_preview' => 'true'],
        ])]);

        $this->assertSame(['A' => '1', 'B' => '2', 'C' => '3', 'D' => '4'], app(Coolify::class)->envs());
    }

    public function test_sem_conexao_tem_status_zero_e_nao_vaza_token(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error: Bearer '.self::TOKEN));

        $e = $this->fails(fn () => app(Coolify::class)->compose(), 0, 'Sem conexão com o painel do Coolify');
        $this->assertNull($e->getPrevious());
    }

    public function test_sem_configuracao_falha_antes_de_qualquer_requisicao(): void
    {
        Http::fake();
        UpdateSettings::setToken(null);
        $this->fails(fn () => app(Coolify::class)->compose(), 0, 'token');

        UpdateSettings::setToken(self::TOKEN);
        UpdateSettings::setPanelUrl(null);
        $this->fails(fn () => app(Coolify::class)->compose(), 0, 'URL do painel');

        UpdateSettings::setPanelUrl('https://painel.example');
        config(['fluxie.resource_uuid' => null]);
        $this->fails(fn () => app(Coolify::class)->compose(), 0, 'serviço');

        Http::assertNothingSent();
    }
}
