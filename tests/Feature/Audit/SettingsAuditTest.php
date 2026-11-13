<?php

namespace Tests\Feature\Audit;

use App\Enums\GoogleConnectionStatus;
use App\Enums\UserRole;
use App\Livewire\Settings\ChannelCard;
use App\Livewire\Settings\Channels;
use App\Livewire\Settings\GoogleContacts;
use App\Livewire\Settings\Retention;
use App\Models\AuditLog;
use App\Models\Channel;
use App\Models\GoogleConnection;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class SettingsAuditTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::ADMIN]);
    }

    private function fakeGoogleUser(string $email = 'dimetal@gmail.com'): void
    {
        $user = (new SocialiteUser)->map(['email' => $email]);
        $user->token = 'access-token-secreto';
        $user->refreshToken = 'refresh-token-secreto';
        $user->expiresIn = 3600;

        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('user')->andReturn($user);
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    public function test_conectar_o_google_grava_a_conta_e_nunca_o_token(): void
    {
        $this->fakeGoogleUser();
        $admin = $this->admin();

        $this->actingAs($admin)->get('/configuracoes/google/callback?code=abc')->assertRedirect('/configuracoes/google');

        $log = AuditLog::query()->sole();
        $this->assertSame('google.connected', $log->action);
        $this->assertSame('dimetal@gmail.com', $log->subject);
        $this->assertSame($admin->id, $log->actor_user_id);
        $this->assertStringNotContainsString('secreto', (string) json_encode(AuditLog::query()->get()->toArray()));
    }

    public function test_conexao_negada_pelo_google_nao_grava(): void
    {
        $this->actingAs($this->admin())->get('/configuracoes/google/callback?error=access_denied')->assertRedirect('/configuracoes/google');

        $this->assertSame(0, AuditLog::query()->count());
    }

    public function test_desconectar_o_google_grava_a_conta_que_saiu(): void
    {
        GoogleConnection::query()->create(['account_email' => 'dimetal@gmail.com', 'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => now()->addHour(), 'status' => GoogleConnectionStatus::CONNECTED, 'connected_at' => now()]);

        Livewire::actingAs($this->admin())->test(GoogleContacts::class)->call('disconnect');

        $log = AuditLog::query()->sole();
        $this->assertSame('google.disconnected', $log->action);
        $this->assertSame('dimetal@gmail.com', $log->subject);
    }

    public function test_criar_canal_grava_e_sessao_duplicada_nao(): void
    {
        $admin = $this->admin();
        $page = Livewire::actingAs($admin)->test(Channels::class)->set('sessionName', 'nova-sessao')->call('addChannel')->assertHasNoErrors();

        $page->set('sessionName', 'nova-sessao')->call('addChannel')->assertHasErrors('sessionName');

        $log = AuditLog::query()->sole();
        $this->assertSame('channel.created', $log->action);
        $this->assertSame('nova-sessao', $log->subject);
        $this->assertSame($admin->id, $log->actor_user_id);
    }

    public function test_apagar_canal_grava_e_canal_com_historico_nao(): void
    {
        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
        Http::fake(['*/api/sessions/*' => Http::response([], 200)]);
        $admin = $this->admin();
        $empty = Channel::factory()->create(['waha_session' => 'vazio', 'name' => 'Canal Vazio']);
        $used = Channel::factory()->create(['waha_session' => 'usado', 'name' => 'Canal Usado']);
        Ticket::factory()->create(['channel_id' => $used->id]);

        Livewire::actingAs($admin)->test(ChannelCard::class, ['channel' => $used])->call('deleteChannel');
        $this->assertSame(0, AuditLog::query()->count());

        Livewire::actingAs($admin)->test(ChannelCard::class, ['channel' => $empty])->call('deleteChannel');

        $log = AuditLog::query()->sole();
        $this->assertSame('channel.deleted', $log->action);
        $this->assertSame('Canal Vazio', $log->subject);
    }

    public function test_expurgo_manual_grava_o_pedido(): void
    {
        Queue::fake();
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(Retention::class)->call('purgeNow');

        $log = AuditLog::query()->sole();
        $this->assertSame('retention.purge_requested', $log->action);
        $this->assertSame($admin->id, $log->actor_user_id);
    }
}
