<?php

namespace Tests\Feature\Update;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\NewVersionAvailable;
use App\Services\Update\ReleaseFeed;
use App\Settings\UpdateSettings;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ReleaseFeedTest extends TestCase
{
    use RefreshDatabase;

    private const LATEST = 'https://api.github.com/repos/fluxie-tech/fluxie-inbox/releases/latest';

    private const LIST = 'https://api.github.com/repos/fluxie-tech/fluxie-inbox/releases*';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['fluxie.version' => '0.1.0', 'fluxie.update_prerelease' => false, 'fluxie.releases_repo' => 'fluxie-tech/fluxie-inbox']);
    }

    /**
     * @param  array<string, mixed>  $over
     * @return array<string, mixed>
     */
    private function release(string $tag, array $over = [], bool $compose = true): array
    {
        $base = json_decode((string) file_get_contents(base_path('tests/Fixtures/github/github-releases-latest.json')), true);
        $asset = $base['assets'][0];
        $asset['browser_download_url'] = "https://github.com/fluxie-tech/fluxie-inbox/releases/download/{$tag}/docker-compose.yml";

        return array_merge($base, ['tag_name' => $tag, 'assets' => $compose ? [$asset] : []], $over);
    }

    public function test_avisa_os_admins_uma_vez_por_versao(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        User::factory()->create(['role' => UserRole::ATENDENTE]);
        Http::fake([self::LATEST => Http::response($this->release('v0.2.0'))]);

        app(ReleaseFeed::class)->refresh();
        app(ReleaseFeed::class)->refresh();

        Notification::assertSentToTimes($admin, NewVersionAvailable::class, 1);
        Notification::assertCount(1);
        $this->assertSame('0.2.0', UpdateSettings::notifiedVersion());
        $latest = UpdateSettings::latest();
        $this->assertSame('v0.2.0', $latest['tag']);
        $this->assertStringEndsWith('/v0.2.0/docker-compose.yml', $latest['compose_url']);
        $this->assertSame('https://github.com/fluxie-tech/fluxie-inbox/releases/tag/v0.1.0', $latest['notes_url']);
        $this->assertTrue(UpdateSettings::lastCheck()['ok']);
    }

    public function test_versao_nova_depois_da_avisada_avisa_de_novo(): void
    {
        User::factory()->create(['role' => UserRole::ADMIN]);
        Http::fakeSequence()->push($this->release('v0.2.0'))->push($this->release('v0.3.0'));

        app(ReleaseFeed::class)->refresh();
        app(ReleaseFeed::class)->refresh();

        Notification::assertCount(2);
    }

    public function test_nao_avisa_versao_igual_ou_menor(): void
    {
        User::factory()->create(['role' => UserRole::ADMIN]);
        Http::fake([self::LATEST => Http::response($this->release('v0.1.0'))]);

        app(ReleaseFeed::class)->refresh();

        Notification::assertNothingSent();
        $this->assertSame('v0.1.0', UpdateSettings::latest()['tag']);
        $this->assertNull(UpdateSettings::notifiedVersion());
    }

    public function test_instalacao_de_desenvolvimento_registra_mas_nao_avisa(): void
    {
        config(['fluxie.version' => null, 'fluxie.build' => 'sha-abc123']);
        User::factory()->create(['role' => UserRole::ADMIN]);
        Http::fake([self::LATEST => Http::response($this->release('v9.0.0'))]);

        app(ReleaseFeed::class)->refresh();

        Notification::assertNothingSent();
        $this->assertSame('v9.0.0', UpdateSettings::latest()['tag']);
        $this->assertTrue(UpdateSettings::lastCheck()['ok']);
    }

    public function test_pre_release_e_ignorada_por_padrao_e_aceita_com_a_opcao(): void
    {
        User::factory()->create(['role' => UserRole::ADMIN]);
        $list = [
            $this->release('v0.3.0-rc.1', ['prerelease' => true]),
            $this->release('v0.9.0', ['draft' => true]),
            $this->release('v0.2.0'),
            $this->release('v0.4.0', [], false),
        ];
        Http::fake([self::LATEST => Http::response($this->release('v0.2.0')), self::LIST => Http::response($list)]);

        app(ReleaseFeed::class)->refresh();
        $this->assertSame('v0.2.0', UpdateSettings::latest()['tag']);

        config(['fluxie.update_prerelease' => true]);
        app(ReleaseFeed::class)->refresh();

        $this->assertSame('v0.3.0-rc.1', UpdateSettings::latest()['tag']);
    }

    public function test_release_sem_o_asset_do_compose_e_ignorada(): void
    {
        User::factory()->create(['role' => UserRole::ADMIN]);
        Http::fake([self::LATEST => Http::response($this->release('v0.2.0', [], false))]);

        app(ReleaseFeed::class)->refresh();

        Notification::assertNothingSent();
        $this->assertNull(UpdateSettings::latest());
        $this->assertTrue(UpdateSettings::lastCheck()['ok']);
    }

    public function test_falha_de_rede_grava_o_erro_sem_alerta_e_sem_excecao(): void
    {
        User::factory()->create(['role' => UserRole::ADMIN]);
        Http::fake(fn () => throw new ConnectionException('cURL error 6: could not resolve host'));

        app(ReleaseFeed::class)->refresh();

        $check = UpdateSettings::lastCheck();
        $this->assertFalse($check['ok']);
        $this->assertNotEmpty($check['error']);
        $this->assertStringNotContainsString('cURL', (string) $check['error']);
        Notification::assertNothingSent();
    }

    public function test_status_diferente_de_200_e_json_invalido_viram_erro(): void
    {
        Http::fakeSequence()->push('{"message":"rate limit"}', 403)->push('isto nao e json', 200);

        app(ReleaseFeed::class)->refresh();
        $this->assertFalse(UpdateSettings::lastCheck()['ok']);

        app(ReleaseFeed::class)->refresh();
        $this->assertFalse(UpdateSettings::lastCheck()['ok']);
        Notification::assertNothingSent();
    }

    public function test_o_request_leva_so_o_user_agent_e_sem_autenticacao(): void
    {
        Http::fake([self::LATEST => Http::response($this->release('v0.1.0'))]);

        app(ReleaseFeed::class)->refresh();

        Http::assertSent(fn (Request $r): bool => $r->url() === self::LATEST
            && $r->header('User-Agent') === ['Fluxie-Inbox']
            && ! $r->hasHeader('Authorization')
            && $r->body() === '');
    }

    public function test_comando_nao_deixa_escapar_excecao(): void
    {
        Http::fake(fn () => throw new ConnectionException('boom'));

        $this->artisan('app:check-releases')->assertSuccessful();

        $this->assertFalse(UpdateSettings::lastCheck()['ok']);
    }

    public function test_consulta_desligada_o_agendamento_nao_roda(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'app:check-releases'));

        $this->assertNotNull($event);
        $this->assertSame('40 7 * * *', $event->expression);
        $this->assertSame(config('app.display_timezone'), $event->timezone);
        $this->assertTrue($event->filtersPass($this->app));

        UpdateSettings::setCheckEnabled(false);

        $this->assertFalse($event->filtersPass($this->app));
    }

    public function test_token_e_guardado_cifrado_e_removido_com_null(): void
    {
        $this->assertNull(UpdateSettings::token());
        $this->assertNull(UpdateSettings::tokenSavedAt());

        UpdateSettings::setToken('segredo-123');

        $this->assertSame('segredo-123', UpdateSettings::token());
        $this->assertNotNull(UpdateSettings::tokenSavedAt());
        $this->assertStringNotContainsString('segredo-123', (string) Setting::query()->where('key', 'update.token')->value('value'));

        UpdateSettings::setToken(null);

        $this->assertNull(UpdateSettings::token());
        $this->assertNull(UpdateSettings::tokenSavedAt());
    }

    public function test_url_do_painel_e_consulta_ligada_por_padrao(): void
    {
        $this->assertTrue(UpdateSettings::checkEnabled());
        $this->assertNull(UpdateSettings::panelUrl());

        UpdateSettings::setPanelUrl('https://painel.exemplo.com');
        $this->assertSame('https://painel.exemplo.com', UpdateSettings::panelUrl());

        UpdateSettings::setPanelUrl(null);
        $this->assertNull(UpdateSettings::panelUrl());
    }
}
