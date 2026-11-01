<?php

namespace Tests\Feature\Livewire;

use App\Enums\ChannelStatus;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Settings\ChannelCard;
use App\Livewire\Settings\Channels;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Ticket;
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

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::ADMIN]);
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

        $this->actingAs($this->admin())->get('/configuracoes/canais')
            ->assertOk()
            ->assertSee('WhatsApp principal')
            ->assertSee('Conectado');
    }

    public function test_horarios_sao_exibidos_no_fuso_de_sao_paulo(): void
    {
        $channel = Channel::factory()->create(['waha_session' => 'main']);
        $channel->forceFill(['last_event_at' => '2026-10-28 12:00:00'])->save();

        Livewire::actingAs($this->admin())->test(ChannelCard::class, ['channel' => $channel])
            ->assertSee('28/10/2026 09:00:00');
    }

    public function test_lista_um_cartao_por_canal(): void
    {
        Channel::factory()->create(['name' => 'Numero 1', 'waha_session' => 'um']);
        Channel::factory()->create(['name' => 'Numero 2', 'waha_session' => 'dois']);

        Livewire::actingAs($this->admin())->test(Channels::class)
            ->assertSeeLivewire(ChannelCard::class)
            ->assertSee('Numero 1')
            ->assertSee('Numero 2');
    }

    public function test_adiciona_segundo_canal_pelo_nome_da_sessao(): void
    {
        Channel::factory()->create(['waha_session' => 'um']);

        Livewire::actingAs($this->admin())->test(Channels::class)
            ->set('sessionName', 'dois')
            ->call('addChannel')
            ->assertHasNoErrors()
            ->assertSet('sessionName', '');

        $this->assertSame(2, Channel::query()->count());
        $this->assertSame('dois', Channel::query()->where('waha_session', 'dois')->sole()->name);
    }

    public function test_sessao_duplicada_e_rejeitada(): void
    {
        Channel::factory()->create(['waha_session' => 'um']);

        Livewire::actingAs($this->admin())->test(Channels::class)
            ->set('sessionName', 'um')
            ->call('addChannel')
            ->assertHasErrors('sessionName');

        $this->assertSame(1, Channel::query()->count());
    }

    public function test_nome_de_sessao_invalido_e_rejeitado(): void
    {
        Livewire::actingAs($this->admin())->test(Channels::class)
            ->set('sessionName', 'com espaco/e barra')
            ->call('addChannel')
            ->assertHasErrors('sessionName');

        $this->assertSame(0, Channel::query()->count());
    }

    public function test_conectar_chama_o_waha_e_marca_iniciando(): void
    {
        Http::fake(['*/api/sessions*' => Http::response(['status' => 'STARTING'], 200)]);
        $channel = Channel::factory()->create(['waha_session' => 'main']);

        Livewire::actingAs($this->admin())->test(ChannelCard::class, ['channel' => $channel])->call('connect');

        $this->assertSame(ChannelStatus::STARTING, $channel->fresh()->status);
    }

    public function test_conectar_um_canal_nao_afeta_o_outro(): void
    {
        Http::fake(['*/api/sessions*' => Http::response(['status' => 'STARTING'], 200)]);
        $first = Channel::factory()->create(['waha_session' => 'um']);
        $second = Channel::factory()->create(['waha_session' => 'dois']);

        Livewire::actingAs($this->admin())->test(ChannelCard::class, ['channel' => $first])->call('connect');

        $this->assertSame(ChannelStatus::STARTING, $first->fresh()->status);
        $this->assertSame(ChannelStatus::STOPPED, $second->fresh()->status);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/sessions/um') || $request['name'] === 'um');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/sessions/dois') || ($request['name'] ?? null) === 'dois');
    }

    public function test_waha_fora_do_ar_mostra_erro_e_nao_quebra(): void
    {
        Http::fake(['*/api/sessions*' => Http::response(['message' => 'fora do ar'], 500)]);
        $channel = Channel::factory()->create(['waha_session' => 'main']);

        Livewire::actingAs($this->admin())->test(ChannelCard::class, ['channel' => $channel])
            ->call('connect')
            ->assertSet('errorMessage', fn ($message) => str_contains($message, 'WAHA'));
    }

    public function test_qr_aparece_quando_a_sessao_pede_leitura(): void
    {
        Http::fake([
            '*/api/sessions/main' => Http::response(['status' => 'SCAN_QR_CODE'], 200),
            '*/auth/qr*' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png']),
        ]);
        $channel = Channel::factory()->create(['waha_session' => 'main', 'status' => ChannelStatus::SCAN_QR_CODE]);

        Livewire::actingAs($this->admin())->test(ChannelCard::class, ['channel' => $channel])
            ->call('refreshStatus')
            ->assertSet('qr', fn ($qr) => str_starts_with($qr, 'data:image/png;base64,'));
    }

    public function test_codigo_de_pareamento_aparece_sem_esconder_o_qr(): void
    {
        Http::fake([
            '*/auth/request-code' => Http::response(['code' => 'ABCD-ABCD'], 200),
            '*/auth/qr*' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png']),
        ]);
        $channel = Channel::factory()->create(['waha_session' => 'main', 'status' => ChannelStatus::SCAN_QR_CODE]);

        Livewire::actingAs($this->admin())->test(ChannelCard::class, ['channel' => $channel])
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
        $channel = Channel::factory()->create(['waha_session' => 'main']);

        Livewire::actingAs($this->admin())->test(ChannelCard::class, ['channel' => $channel])
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
        $channel = Channel::factory()->create(['waha_session' => 'main', 'status' => ChannelStatus::WORKING]);

        Livewire::actingAs($this->admin())->test(ChannelCard::class, ['channel' => $channel])
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
        $channel = Channel::factory()->create(['waha_session' => 'main']);

        Livewire::actingAs($this->admin())->test(ChannelCard::class, ['channel' => $channel])
            ->set('testPhone', '123')
            ->set('testText', 'oi')
            ->call('sendTest')
            ->assertHasErrors('testPhone');

        Http::assertNothingSent();
    }

    public function test_admin_renomeia_o_canal_existente(): void
    {
        $channel = Channel::factory()->create(['name' => 'Antigo', 'waha_session' => 'main']);

        Livewire::actingAs($this->admin())
            ->test(ChannelCard::class, ['channel' => $channel])
            ->set('channelName', 'Comercial')
            ->call('rename');

        $this->assertSame('Comercial', $channel->fresh()->name);
    }

    private function ticketOn(Channel $channel, TicketStatus $status = TicketStatus::AGUARDANDO): Ticket
    {
        return Ticket::factory()->create(['channel_id' => $channel->id, 'contact_id' => Contact::factory()->create()->id, 'status' => $status]);
    }

    public function test_desativar_para_a_sessao_e_marca_inativo(): void
    {
        Http::fake(['*/api/sessions/main/stop' => Http::response([], 201)]);
        $channel = Channel::factory()->connected()->create(['waha_session' => 'main']);

        Livewire::actingAs($this->admin())->test(ChannelCard::class, ['channel' => $channel])
            ->call('ask', 'deactivate')->assertSet('confirming', 'deactivate')
            ->call('deactivate')->assertSet('confirming', null);

        $this->assertFalse($channel->fresh()->active);
        $this->assertSame(ChannelStatus::STOPPED, $channel->fresh()->status);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/sessions/main/stop'));
    }

    public function test_confirmacao_mostra_a_contagem_de_tickets_abertos(): void
    {
        $channel = Channel::factory()->connected()->create();
        $this->ticketOn($channel);
        $this->ticketOn($channel, TicketStatus::EM_ATENDIMENTO);
        $this->ticketOn($channel, TicketStatus::ENCERRADO);

        Livewire::actingAs($this->admin())->test(ChannelCard::class, ['channel' => $channel])
            ->call('ask', 'deactivate')->assertSee('2 tickets abertos');
    }

    public function test_reativar_volta_a_ativo_sem_iniciar_a_sessao(): void
    {
        Http::fake();
        $channel = Channel::factory()->create(['active' => false]);

        Livewire::actingAs($this->admin())->test(ChannelCard::class, ['channel' => $channel])->call('reactivate');

        $this->assertTrue($channel->fresh()->active);
        Http::assertNothingSent();
    }

    public function test_excluir_sessao_remove_no_waha_e_mantem_canal_e_tickets(): void
    {
        Http::fake(['*/api/sessions/main' => Http::response([], 200)]);
        $channel = Channel::factory()->connected()->create(['waha_session' => 'main']);
        $ticket = $this->ticketOn($channel);

        Livewire::actingAs($this->admin())->test(ChannelCard::class, ['channel' => $channel])
            ->call('ask', 'delete-session')->call('deleteSession')
            ->assertSet('sessionMissing', true)->assertSet('errorMessage', null);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/api/sessions/main'));
        $this->assertSame(ChannelStatus::STOPPED, $channel->fresh()->status);
        $this->assertSame($channel->id, $ticket->fresh()->channel_id);
    }

    public function test_excluir_sessao_com_404_nao_e_erro(): void
    {
        Http::fake(['*/api/sessions/main' => Http::response(['message' => 'not found'], 404)]);
        $channel = Channel::factory()->connected()->create(['waha_session' => 'main']);

        Livewire::actingAs($this->admin())->test(ChannelCard::class, ['channel' => $channel])
            ->call('deleteSession')->assertSet('errorMessage', null);

        $this->assertSame(ChannelStatus::STOPPED, $channel->fresh()->status);
    }

    public function test_refresh_com_sessao_inexistente_mostra_sessao_removida_sem_erro(): void
    {
        Http::fake(['*/api/sessions/main' => Http::response(['message' => 'not found'], 404)]);
        $channel = Channel::factory()->connected()->create(['waha_session' => 'main']);

        Livewire::actingAs($this->admin())->test(ChannelCard::class, ['channel' => $channel])
            ->call('refreshStatus')->assertSet('sessionMissing', true)->assertSet('errorMessage', null);

        $this->assertSame(ChannelStatus::STOPPED, $channel->fresh()->status);
    }

    public function test_readicionar_a_mesma_sessao_reaproveita_o_canal_e_os_tickets(): void
    {
        Http::fake(fn ($request) => $request->method() === 'GET' ? Http::response(['message' => 'not found'], 404) : Http::response(['status' => 'STARTING'], 201));
        $channel = Channel::factory()->create(['waha_session' => 'main']);
        $ticket = $this->ticketOn($channel);

        Livewire::actingAs($this->admin())->test(Channels::class)
            ->set('sessionName', 'main')->call('addChannel')->assertHasErrors(['sessionName' => 'unique']);

        Livewire::actingAs($this->admin())->test(ChannelCard::class, ['channel' => $channel])->call('connect');

        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/api/sessions') && $request['name'] === 'main');
        $this->assertSame(1, Channel::query()->count());
        $this->assertSame($channel->id, $ticket->fresh()->channel_id);
    }

    public function test_apagar_canal_so_vazio(): void
    {
        $empty = Channel::factory()->create(['waha_session' => 'vazio']);
        $used = Channel::factory()->create(['waha_session' => 'usado']);
        $this->ticketOn($used, TicketStatus::ENCERRADO);

        Livewire::actingAs($this->admin())->test(ChannelCard::class, ['channel' => $empty])
            ->call('deleteChannel')->assertDispatched('channel-deleted');
        Livewire::actingAs($this->admin())->test(ChannelCard::class, ['channel' => $used])
            ->call('deleteChannel')->assertNotDispatched('channel-deleted')
            ->assertSet('errorMessage', fn ($message) => str_contains($message, 'histórico'));

        $this->assertNull(Channel::query()->find($empty->id));
        $this->assertNotNull(Channel::query()->find($used->id));
    }

    private function fakeQr(string $status, int $rawStatus = 200): void
    {
        Http::fake(fn ($request) => match (true) {
            str_contains($request->url(), '/auth/qr') && ($request['format'] ?? null) === 'raw' => Http::response($rawStatus === 200 ? ['value' => 'abc,def'] : [], $rawStatus),
            str_contains($request->url(), '/auth/qr') => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png']),
            default => Http::response(['status' => $status], 200),
        });
    }

    public function test_refresh_status_guarda_o_texto_cru_do_qr(): void
    {
        $this->fakeQr('SCAN_QR_CODE');
        $channel = Channel::factory()->create(['waha_session' => 'main', 'status' => ChannelStatus::SCAN_QR_CODE]);

        Livewire::actingAs($this->admin())->test(ChannelCard::class, ['channel' => $channel])
            ->call('refreshStatus')->assertSet('qrValue', 'abc,def')->assertSet('qr', null);
    }

    public function test_qr_raw_falho_usa_o_png_do_waha(): void
    {
        $this->fakeQr('SCAN_QR_CODE', 422);
        $channel = Channel::factory()->create(['waha_session' => 'main', 'status' => ChannelStatus::SCAN_QR_CODE]);

        Livewire::actingAs($this->admin())->test(ChannelCard::class, ['channel' => $channel])
            ->call('refreshStatus')->assertSet('qrValue', null)
            ->assertSet('qr', fn ($qr) => str_starts_with((string) $qr, 'data:image/png'));
    }

    public function test_falha_no_desenho_do_navegador_cai_no_png(): void
    {
        $this->fakeQr('SCAN_QR_CODE');
        $channel = Channel::factory()->create(['waha_session' => 'main', 'status' => ChannelStatus::SCAN_QR_CODE]);

        Livewire::actingAs($this->admin())->test(ChannelCard::class, ['channel' => $channel])
            ->call('refreshStatus')->call('qrFallback')
            ->assertSet('qrValue', null)->assertSet('qr', fn ($qr) => str_starts_with((string) $qr, 'data:image/png'))
            ->call('refreshStatus')->assertSet('qrValue', null);
    }

    public function test_qr_some_quando_conecta(): void
    {
        $this->fakeQr('WORKING');
        $channel = Channel::factory()->create(['waha_session' => 'main', 'status' => ChannelStatus::SCAN_QR_CODE]);

        Livewire::actingAs($this->admin())->test(ChannelCard::class, ['channel' => $channel])
            ->set('qr', 'data:image/png;base64,AAA')->set('qrValue', 'abc')
            ->call('refreshStatus')->assertSet('qr', null)->assertSet('qrValue', null);
    }

    public function test_tela_mostra_o_aviso_de_failover_e_o_webhook(): void
    {
        Channel::factory()->create();

        $this->actingAs($this->admin())->get('/configuracoes/canais')->assertOk()
            ->assertSee('Sem failover entre números')
            ->assertSee('Webhook de entrada')
            ->assertSee(url('/webhooks/waha'))
            ->assertSee('o mesmo nome de sessão');
    }

    public function test_cartao_conectado_com_historico_mostra_as_acoes_certas(): void
    {
        $channel = Channel::factory()->connected()->create(['waha_session' => 'main']);
        $this->ticketOn($channel, TicketStatus::ENCERRADO);

        Livewire::actingAs($this->admin())->test(ChannelCard::class, ['channel' => $channel])
            ->assertSee(['Engine', 'Sessão', 'Último evento', 'Reconectar', 'Desconectar', 'Desativar', 'Excluir sessão'])
            ->assertDontSee('Apagar canal')
            ->assertDontSee('Reativar');
    }

    public function test_cartao_de_canal_vazio_oferece_apagar_canal(): void
    {
        Livewire::actingAs($this->admin())->test(ChannelCard::class, ['channel' => Channel::factory()->create()])
            ->assertSee('Apagar canal')->assertSee('Conectar');
    }

    public function test_cartao_aguardando_qr_oferece_gerar_novo_qr(): void
    {
        $channel = Channel::factory()->create(['status' => ChannelStatus::SCAN_QR_CODE]);

        Livewire::actingAs($this->admin())->test(ChannelCard::class, ['channel' => $channel])->assertSee('Gerar novo QR');
    }

    public function test_cartao_desativado_mostra_reativar_e_esconde_conectar(): void
    {
        $channel = Channel::factory()->create(['active' => false]);

        Livewire::actingAs($this->admin())->test(ChannelCard::class, ['channel' => $channel])
            ->assertSee('Desativado')->assertSee('Reativar')->assertSee('Excluir sessão')
            ->assertDontSee('Desconectar')->assertDontSee('Desativar');
    }

    public function test_cartao_mostra_sessao_removida_e_a_confirmacao(): void
    {
        Http::fake(['*/api/sessions/main' => Http::response(['message' => 'not found'], 404)]);
        $channel = Channel::factory()->create(['waha_session' => 'main']);

        Livewire::actingAs($this->admin())->test(ChannelCard::class, ['channel' => $channel])
            ->call('refreshStatus')->assertSee('Sessão removida')
            ->call('ask', 'delete-session')->assertSee('Confirmar')->assertSee('Cancelar');
    }

    public function test_ask_recusa_acao_desconhecida(): void
    {
        Livewire::actingAs($this->admin())->test(ChannelCard::class, ['channel' => Channel::factory()->create()])
            ->call('ask', 'drop')->assertSet('confirming', null);
    }
}
