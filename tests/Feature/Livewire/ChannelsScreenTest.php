<?php

namespace Tests\Feature\Livewire;

use App\Enums\ChannelStatus;
use App\Enums\UserRole;
use App\Livewire\Settings\Channels;
use App\Models\Channel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ChannelsScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
    }

    /** @return array<string, array{UserRole}> */
    public static function papeisSemAcesso(): array
    {
        return [
            'supervisor' => [UserRole::SUPERVISOR],
            'atendente' => [UserRole::ATENDENTE],
        ];
    }

    #[DataProvider('papeisSemAcesso')]
    public function test_supervisor_e_atendente_nao_entram(UserRole $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->get('/configuracoes/canais')->assertForbidden();
    }

    public function test_admin_ve_o_canal_e_o_status(): void
    {
        Channel::factory()->create([
            'name' => 'WhatsApp principal',
            'status' => ChannelStatus::WORKING,
        ]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);

        $this->actingAs($admin)->get('/configuracoes/canais')
            ->assertOk()
            ->assertSee('WhatsApp principal')
            ->assertSee('Conectado');
    }

    public function test_conectar_chama_o_waha_e_marca_iniciando(): void
    {
        Http::fake(['*/api/sessions*' => Http::response(['status' => 'STARTING'], 200)]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $channel = Channel::factory()->create(['waha_session' => 'main']);

        Livewire::actingAs($admin)->test(Channels::class)->call('connect');

        $this->assertSame(ChannelStatus::STARTING, $channel->fresh()->status);
    }

    public function test_waha_fora_do_ar_mostra_erro_e_nao_quebra(): void
    {
        Http::fake(['*/api/sessions*' => Http::response(['message' => 'fora do ar'], 500)]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        Channel::factory()->create(['waha_session' => 'main']);

        Livewire::actingAs($admin)->test(Channels::class)
            ->call('connect')
            ->assertSet('errorMessage', fn ($message) => str_contains($message, 'WAHA'));
    }

    public function test_qr_aparece_quando_a_sessao_pede_leitura(): void
    {
        Http::fake([
            '*/api/sessions/main' => Http::response(['status' => 'SCAN_QR_CODE'], 200),
            '*/auth/qr*' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png']),
        ]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        Channel::factory()->create(['waha_session' => 'main', 'status' => ChannelStatus::SCAN_QR_CODE]);

        Livewire::actingAs($admin)->test(Channels::class)
            ->call('refreshStatus')
            ->assertSet('qr', fn ($qr) => str_starts_with($qr, 'data:image/png;base64,'));
    }

    public function test_codigo_de_pareamento_aparece_sem_esconder_o_qr(): void
    {
        Http::fake([
            '*/auth/request-code' => Http::response(['code' => 'ABCD-ABCD'], 200),
            '*/auth/qr*' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png']),
        ]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        Channel::factory()->create(['waha_session' => 'main', 'status' => ChannelStatus::SCAN_QR_CODE]);

        Livewire::actingAs($admin)->test(Channels::class)
            ->set('pairingPhone', '+55 (16) 99812-4477')
            ->call('requestPairingCode')
            ->assertSet('pairingCode', 'ABCD-ABCD')
            ->assertSet('qr', fn ($qr) => str_starts_with($qr, 'data:image/png;base64,'));

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/auth/request-code')
            && $request['phoneNumber'] === '5516998124477');
    }

    public function test_importacao_de_historico_vem_desligada_e_e_gravada_na_conexao(): void
    {
        Http::fake(['*/api/sessions*' => Http::response(['status' => 'STARTING'], 200)]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $channel = Channel::factory()->create(['waha_session' => 'main']);

        Livewire::actingAs($admin)->test(Channels::class)
            ->assertSet('backfill', false)
            ->assertSet('backfillDays', 5)
            ->set('backfill', true)
            ->call('connect');

        $this->assertSame(5, $channel->fresh()->backfill_days);
    }

    public function test_envia_texto_de_teste_normalizando_o_telefone(): void
    {
        Http::fake([
            '*/api/sendText' => Http::response(['id' => 'ABC123'], 201),
            '*/presence' => Http::response([], 200),
        ]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        Channel::factory()->create(['waha_session' => 'main', 'status' => ChannelStatus::WORKING]);

        Livewire::actingAs($admin)->test(Channels::class)
            ->set('testPhone', '+55 (16) 99812-4477')
            ->set('testText', 'Teste do painel')
            ->call('sendTest')
            ->assertSet('sentMessage', 'Enviado: ABC123');

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/sendText')
            && $request['chatId'] === '5516998124477@c.us'
            && $request['text'] === 'Teste do painel');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/presence')
            && $request['presence'] === 'offline');
    }

    public function test_telefone_invalido_nao_chama_o_waha(): void
    {
        Http::fake();
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        Channel::factory()->create(['waha_session' => 'main']);

        Livewire::actingAs($admin)->test(Channels::class)
            ->set('testPhone', '123')
            ->set('testText', 'oi')
            ->call('sendTest')
            ->assertHasErrors('testPhone');

        Http::assertNothingSent();
    }

    public function test_admin_renomeia_o_canal_existente(): void
    {
        $channel = Channel::factory()->create(['name' => 'Antigo', 'waha_session' => 'main']);

        Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))
            ->test(Channels::class)
            ->set('channelName', 'Comercial')
            ->call('rename');

        $this->assertSame('Comercial', $channel->fresh()->name);
    }
}
