<?php

namespace Tests\Feature\Update;

use App\Models\Setting;
use App\Services\Backup\ManualBackupState;
use App\Services\Backup\Restore\RestoreProgress;
use App\Services\Update\Preflight;
use App\Services\Update\Version;
use App\Settings\UpdateSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PreflightTest extends TestCase
{
    use FakesUpdateWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareUpdateSettings();
    }

    private function target(): Version
    {
        return Version::parse('0.2.0') ?? throw new RuntimeException('versão');
    }

    /** @return list<string> */
    private function check(?string $compose = null): array
    {
        $this->fakeWorld();

        return app(Preflight::class)->check($this->target(), $compose ?? $this->releaseCompose());
    }

    /** @return list<string> */
    private function connection(): array
    {
        $this->fakeWorld();

        return app(Preflight::class)->connection();
    }

    private function envWrites(): array
    {
        return array_values(array_filter($this->writes(), fn (Request $r): bool => str_ends_with($r->url(), '/envs')));
    }

    public function test_tudo_certo_nao_devolve_erro(): void
    {
        $this->assertSame([], $this->check());
    }

    public function test_variavel_ausente_e_criada_com_a_versao_rodando(): void
    {
        $this->assertSame([], $this->connection());

        $writes = $this->envWrites();
        $this->assertSame(['PATCH', 'POST'], array_map(fn (Request $r): string => $r->method(), $writes));
        $this->assertSame(['key' => 'FLUXIE_VERSION', 'value' => '0.1.0', 'is_preview' => false], $writes[1]->data());
    }

    public function test_variavel_presente_e_vazia_recebe_a_versao_rodando(): void
    {
        $this->envs['FLUXIE_VERSION'] = '';

        $this->assertSame([], $this->connection());

        $writes = $this->envWrites();
        $this->assertCount(1, $writes);
        $this->assertSame('PATCH', $writes[0]->method());
        $this->assertSame('0.1.0', $writes[0]->data()['value']);
    }

    public function test_variavel_com_valor_e_regravada_igual(): void
    {
        $this->envs['FLUXIE_VERSION'] = '0.1.5';

        $this->assertSame([], $this->connection());

        $this->assertSame('0.1.5', $this->envWrites()[0]->data()['value']);
    }

    public function test_sem_read_sensitive_para_antes_de_qualquer_escrita(): void
    {
        $this->world['service_get'] = [200, ['uuid' => 'svc123', 'name' => 'fluxie-inbox']];

        $errors = $this->connection();

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('read:sensitive', $errors[0]);
        $this->assertNoCoolifyWrites();
        Http::assertNotSent(fn (Request $r): bool => str_ends_with($r->url(), '/envs'));
    }

    public function test_service_inexistente_para_na_leitura(): void
    {
        $this->world['service_get'] = [404, ['message' => 'Service not found.']];

        $this->assertStringContainsString('não encontrado', $this->connection()[0]);
        $this->assertNoCoolifyWrites();
    }

    public function test_sem_ler_variaveis_para_antes_de_qualquer_escrita(): void
    {
        $this->world['envs_get'] = [403, ['message' => 'Missing required permissions: read']];

        $this->assertStringContainsString('read', $this->connection()[0]);
        $this->assertNoCoolifyWrites();
    }

    public function test_sem_deploy_para_sem_nenhuma_escrita(): void
    {
        $this->world['probe'] = [403, ['message' => 'Missing required permissions: deploy']];

        $errors = $this->connection();

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('deploy', $errors[0]);
        $this->assertNoCoolifyWrites();
    }

    public function test_sem_write_a_gravacao_de_prova_falha(): void
    {
        $this->world['envs_patch'] = [403, ['message' => 'Missing required permissions: write']];

        $errors = $this->connection();

        $this->assertStringContainsString('write', $errors[0]);
        $this->assertSame(['PATCH'], array_map(fn (Request $r): string => $r->method(), $this->writes())); // só a tentativa recusada
        $this->assertSame([], array_filter($this->writes(), fn (Request $r): bool => ! str_ends_with($r->url(), '/envs')));
    }

    public function test_erro_de_conexao_nao_deixa_o_check_seguir_para_o_ghcr(): void
    {
        $this->world['probe'] = [403, ['message' => 'Missing required permissions: deploy']];

        $errors = $this->check();

        $this->assertNotEmpty($errors);
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), 'ghcr.io'));
    }

    public function test_ghcr_e_consultado_com_token_anonimo_e_accept_de_manifesto(): void
    {
        $this->assertSame([], $this->check());

        Http::assertSent(fn (Request $r): bool => urldecode($r->url()) === 'https://ghcr.io/token?scope=repository:fluxie-tech/fluxie-inbox:pull');
        Http::assertSent(fn (Request $r): bool => $r->method() === 'GET'
            && $r->url() === 'https://ghcr.io/v2/fluxie-tech/fluxie-inbox/manifests/0.2.0'
            && $r->hasHeader('Authorization', 'Bearer anon')
            && $r->header('Accept') === [self::ACCEPT]);
    }

    public function test_imagem_inexistente_no_ghcr(): void
    {
        $this->world['manifest'] = [404, $this->fixture('ghcr-manifest-9.9.9-naoexiste.json')];

        $errors = $this->check();

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('0.2.0', $errors[0]);
        $this->assertStringContainsString('GHCR', $errors[0]);
    }

    public function test_ghcr_fora_do_ar_nao_e_confundido_com_imagem_inexistente(): void
    {
        $this->world['ghcr_token'] = [503, 'indisponível'];

        $this->assertStringContainsString('GHCR', $this->check()[0]);
    }

    /** @return array<string, array{string, string}> */
    public static function composesInvalidos(): array
    {
        return [
            'yaml quebrado' => ["services:\n  app: [\n", 'YAML'],
            'sem o serviço app' => ["services:\n  web:\n    image: ghcr.io/fluxie-tech/fluxie-inbox:\${FLUXIE_VERSION:-0.2.0}\n", 'app'],
            'imagem de outro repositório' => ["services:\n  app:\n    image: outra/imagem:\${FLUXIE_VERSION:-0.2.0}\n", 'imagem'],
            'versão padrão errada' => ["services:\n  app:\n    image: ghcr.io/fluxie-tech/fluxie-inbox:\${FLUXIE_VERSION:-0.1.0}\n", 'imagem'],
            'imagem sem a variável' => ["services:\n  app:\n    image: ghcr.io/fluxie-tech/fluxie-inbox:0.2.0\n", 'imagem'],
        ];
    }

    #[DataProvider('composesInvalidos')]
    public function test_compose_da_release_invalido(string $compose, string $mention): void
    {
        $errors = $this->check($compose);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString($mention, $errors[0]);
    }

    public function test_compose_real_da_release_com_ancora_e_valido(): void
    {
        $this->assertSame([], $this->check($this->releaseCompose()));
    }

    public function test_restauracao_em_andamento_bloqueia(): void
    {
        app(RestoreProgress::class)->begin('Restaurando', ['inspect']);

        $this->assertStringContainsString('restauração', $this->check()[0]);
    }

    public function test_backup_manual_em_andamento_bloqueia(): void
    {
        app(ManualBackupState::class)->markStarted();

        $this->assertStringContainsString('backup', $this->check()[0]);
    }

    public function test_baixa_o_compose_da_release_pelo_asset_do_latest(): void
    {
        $this->fakeWorld();

        $this->assertSame($this->releaseCompose(), app(Preflight::class)->releaseCompose($this->target()));
        Http::assertSent(fn (Request $r): bool => $r->url() === self::RELEASE_URL && $r->hasHeader('User-Agent', 'Fluxie-Inbox'));
    }

    public function test_latest_de_outra_versao_pede_nova_consulta(): void
    {
        $this->fakeWorld();
        UpdateSettings::setLatest(['tag' => 'v0.3.0', 'published_at' => '', 'notes_url' => '', 'compose_url' => 'https://x/y.yml']);

        try {
            app(Preflight::class)->releaseCompose($this->target());
            $this->fail('Esperava RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertSame('Consulte as versões de novo antes de atualizar.', $e->getMessage());
        }
        Http::assertNothingSent();
    }

    public function test_sem_latest_guardado_pede_nova_consulta(): void
    {
        $this->fakeWorld();
        Setting::query()->where('key', 'update.latest')->delete();

        $this->expectExceptionMessage('Consulte as versões de novo');

        app(Preflight::class)->releaseCompose($this->target());
    }

    public function test_download_do_compose_da_release_falhando(): void
    {
        $this->world['release_compose'] = [404, 'Not Found'];
        $this->fakeWorld();

        $this->expectExceptionMessage('docker-compose.yml da Release');

        app(Preflight::class)->releaseCompose($this->target());
    }

    public function test_compose_editado_quando_difere_do_asset_da_versao_atual(): void
    {
        $this->world['installed_compose'] = [200, $this->fixture('release-0.1.0-docker-compose.yml')];
        $this->fakeWorld();

        $this->assertTrue(app(Preflight::class)->composeEdited());
        Http::assertSent(fn (Request $r): bool => $r->url() === 'https://github.com/fluxie-tech/fluxie-inbox/releases/download/v0.1.0/docker-compose.yml');
    }

    public function test_compose_igual_ao_asset_nao_e_editado_mesmo_com_espaco_no_fim(): void
    {
        $this->world['installed_compose'] = [200, "abc\n"];
        $this->world['service_get'] = [200, ['docker_compose_raw' => "abc\n\n"]];
        $this->fakeWorld();

        $this->assertFalse(app(Preflight::class)->composeEdited());
    }

    public function test_compose_editado_e_falso_quando_nao_da_para_baixar(): void
    {
        $this->world['installed_compose'] = [404, 'Not Found'];
        $this->fakeWorld();

        $this->assertFalse(app(Preflight::class)->composeEdited());
    }

    public function test_compose_editado_e_falso_em_versao_de_desenvolvimento_sem_chamar_a_rede(): void
    {
        config(['fluxie.version' => 'sha-abc1234']);
        Http::fake();
        $this->assertFalse(app(Preflight::class)->composeEdited());
        Http::assertNothingSent();
    }
}
