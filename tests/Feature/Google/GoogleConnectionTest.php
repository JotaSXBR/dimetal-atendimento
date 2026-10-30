<?php

namespace Tests\Feature\Google;

use App\Enums\GoogleConnectionStatus;
use App\Enums\UserRole;
use App\Livewire\Settings\GoogleContacts;
use App\Models\Contact;
use App\Models\GoogleConnection;
use App\Models\User;
use App\Services\Google\GoogleAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class GoogleConnectionTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google' => ['client_id' => 'cid', 'client_secret' => 'secret', 'redirect' => 'http://localhost/configuracoes/google/callback']]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::ADMIN]);
    }

    private function connection(array $override = []): GoogleConnection
    {
        return GoogleConnection::query()->create(array_replace([
            'account_email' => 'dimetal@gmail.com',
            'access_token' => 'access-1',
            'refresh_token' => 'refresh-1',
            'expires_at' => now()->addHour(),
            'status' => GoogleConnectionStatus::CONNECTED,
            'connected_at' => now(),
        ], $override));
    }

    public function test_quem_nao_e_admin_recebe_403_nas_tres_rotas(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::SUPERVISOR]));

        $this->get('/configuracoes/google')->assertForbidden();
        $this->get('/configuracoes/google/redirect')->assertForbidden();
        $this->get('/configuracoes/google/callback')->assertForbidden();
    }

    public function test_redirect_pede_acesso_offline_consentimento_e_os_escopos(): void
    {
        $response = $this->actingAs($this->admin())->get('/configuracoes/google/redirect');

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.google.com/', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('offline', $query['access_type']);
        $this->assertSame('consent', $query['prompt']);
        $this->assertStringContainsString('https://www.googleapis.com/auth/contacts', $query['scope']);
        $this->assertStringContainsString('email', $query['scope']);
        $this->assertSame('cid', $query['client_id']);
    }

    private function fakeGoogleUser(string $email = 'dimetal@gmail.com'): void
    {
        $user = (new SocialiteUser)->map(['email' => $email]);
        $user->token = 'access-new';
        $user->refreshToken = 'refresh-new';
        $user->expiresIn = 3600;

        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('user')->andReturn($user);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_callback_grava_a_conta_com_tokens_cifrados(): void
    {
        $this->fakeGoogleUser();

        $this->actingAs($this->admin())->get('/configuracoes/google/callback?code=abc')->assertRedirect('/configuracoes/google');

        $connection = GoogleConnection::query()->firstOrFail();
        $this->assertSame('dimetal@gmail.com', $connection->account_email);
        $this->assertSame(GoogleConnectionStatus::CONNECTED, $connection->status);
        $this->assertSame('access-new', $connection->access_token);
        $this->assertSame('refresh-new', $connection->refresh_token);
        $raw = DB::table('google_connections')->first();
        $this->assertNotSame('access-new', $raw?->access_token);
        $this->assertNotSame('refresh-new', $raw?->refresh_token);
    }

    public function test_reconectar_substitui_a_linha_unica(): void
    {
        $this->connection(['status' => GoogleConnectionStatus::NEEDS_REAUTH]);
        $this->fakeGoogleUser('outra@gmail.com');

        $this->actingAs($this->admin())->get('/configuracoes/google/callback?code=abc');

        $this->assertSame(1, GoogleConnection::query()->count());
        $this->assertSame('outra@gmail.com', GoogleConnection::query()->firstOrFail()->account_email);
        $this->assertSame(GoogleConnectionStatus::CONNECTED, GoogleConnection::query()->firstOrFail()->status);
    }

    public function test_usuario_que_nega_o_acesso_volta_com_aviso_e_nada_e_gravado(): void
    {
        $this->actingAs($this->admin())->get('/configuracoes/google/callback?error=access_denied')
            ->assertRedirect('/configuracoes/google')
            ->assertSessionHas('google_error');

        $this->assertSame(0, GoogleConnection::query()->count());
    }

    public function test_token_valido_nao_chama_a_rede(): void
    {
        Http::fake();
        $this->connection();

        $this->assertSame('access-1', app(GoogleAccount::class)->accessToken());

        Http::assertNothingSent();
    }

    public function test_token_vencido_e_renovado_e_salvo(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['access_token' => 'access-2', 'expires_in' => 3599], 200)]);
        $this->connection(['expires_at' => now()->addSeconds(30)]);

        $this->assertSame('access-2', app(GoogleAccount::class)->accessToken());

        Http::assertSent(fn ($r) => $r->url() === self::TOKEN_URL && $r['grant_type'] === 'refresh_token' && $r['refresh_token'] === 'refresh-1' && $r['client_id'] === 'cid');
        $fresh = GoogleConnection::query()->firstOrFail();
        $this->assertSame('access-2', $fresh->access_token);
        $this->assertSame('refresh-1', $fresh->refresh_token);
        $this->assertTrue($fresh->expires_at->isFuture());
    }

    public function test_invalid_grant_marca_reconexao_e_devolve_null(): void
    {
        Http::fake([self::TOKEN_URL => Http::response(['error' => 'invalid_grant', 'error_description' => 'Token has been expired or revoked.'], 400)]);
        $this->connection(['expires_at' => now()->subMinute()]);

        $this->assertNull(app(GoogleAccount::class)->accessToken());

        $connection = GoogleConnection::query()->firstOrFail();
        $this->assertSame(GoogleConnectionStatus::NEEDS_REAUTH, $connection->status);
        $this->assertSame('invalid_grant', $connection->last_error);
        $this->assertFalse(app(GoogleAccount::class)->isConnected());
    }

    public function test_falha_passageira_nao_derruba_a_conexao(): void
    {
        Http::fake([self::TOKEN_URL => Http::response('boom', 503)]);
        $this->connection(['expires_at' => now()->subMinute()]);

        $this->assertNull(app(GoogleAccount::class)->accessToken());

        $this->assertSame(GoogleConnectionStatus::CONNECTED, GoogleConnection::query()->firstOrFail()->status);
        $this->assertSame('token_refresh_failed', GoogleConnection::query()->firstOrFail()->last_error);
    }

    public function test_conexao_que_precisa_reautenticar_nao_chama_a_rede(): void
    {
        Http::fake();
        $this->connection(['status' => GoogleConnectionStatus::NEEDS_REAUTH, 'expires_at' => now()->subHour()]);

        $this->assertNull(app(GoogleAccount::class)->accessToken());

        Http::assertNothingSent();
    }

    public function test_desconectar_apaga_a_linha_e_os_tokens(): void
    {
        $this->connection();

        app(GoogleAccount::class)->disconnect();

        $this->assertSame(0, GoogleConnection::query()->count());
        $this->assertNull(app(GoogleAccount::class)->current());
        $this->assertFalse(app(GoogleAccount::class)->isConnected());
    }

    public function test_pagina_mostra_conta_estado_e_contagens(): void
    {
        $this->connection();
        Contact::factory()->edited()->create(['google_synced_at' => now(), 'google_resource_name' => 'people/c1']);
        Contact::factory()->edited()->create();
        Contact::factory()->create();

        Livewire::actingAs($this->admin())->test(GoogleContacts::class)
            ->assertSee('dimetal@gmail.com')
            ->assertSee('Conectado')
            ->assertSee('1 sincronizado')
            ->assertSee('1 pendente');
    }

    public function test_pagina_oferece_reconectar_quando_o_acesso_foi_revogado(): void
    {
        $this->connection(['status' => GoogleConnectionStatus::NEEDS_REAUTH, 'last_error' => 'invalid_grant']);

        Livewire::actingAs($this->admin())->test(GoogleContacts::class)->assertSee('Reconectar')->assertSee('invalid_grant');
    }

    public function test_pagina_desconecta(): void
    {
        $this->connection();

        Livewire::actingAs($this->admin())->test(GoogleContacts::class)->call('disconnect');

        $this->assertSame(0, GoogleConnection::query()->count());
    }

    public function test_so_admin_desconecta_pela_pagina(): void
    {
        $this->connection();

        Livewire::actingAs(User::factory()->create(['role' => UserRole::SUPERVISOR]))->test(GoogleContacts::class)->assertForbidden();

        $this->assertSame(1, GoogleConnection::query()->count());
    }
}
