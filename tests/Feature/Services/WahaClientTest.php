<?php

namespace Tests\Feature\Services;

use App\Models\Channel;
use App\Services\Waha\WahaClient;
use App\Services\Waha\WahaQr;
use App\Services\Waha\WahaRequestException;
use App\Settings\WahaSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WahaClientTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
    }

    public function test_envia_texto_com_a_sessao_e_a_api_key(): void
    {
        Http::fake(['*/api/sendText' => Http::response(['id' => 'false_5516@c.us_ABC'], 201)]);
        $channel = Channel::factory()->create(['waha_session' => 'main']);

        $response = (new WahaClient($channel))->sendText('5516998124477@c.us', 'Olá, ação e coração 🚀');

        $this->assertSame('false_5516@c.us_ABC', $response['id']);

        Http::assertSent(fn ($request) => $request->url() === 'http://waha.test:3000/api/sendText'
            && $request['session'] === 'main'
            && $request['chatId'] === '5516998124477@c.us'
            && $request['text'] === 'Olá, ação e coração 🚀'
            && $request->hasHeader('X-Api-Key', 'test-key'));
    }

    public function test_erro_do_waha_vira_excecao_legivel(): void
    {
        Http::fake(['*/api/sendText' => Http::response(['message' => 'Session not found'], 500)]);
        $channel = Channel::factory()->create();

        $this->expectException(WahaRequestException::class);

        (new WahaClient($channel))->sendText('5516998124477@c.us', 'oi');
    }

    public function test_sessao_existente_reaplica_config_e_inicia(): void
    {
        Http::fake([
            '*/api/sessions/main' => Http::response([], 200),
            '*/api/sessions/main/start' => Http::response(['status' => 'STARTING'], 200),
        ]);
        $channel = Channel::factory()->create(['waha_session' => 'main']);

        (new WahaClient($channel))->start();

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'http://waha.test:3000/api/sessions/main/start');
    }

    public function test_qr_volta_como_data_uri_de_imagem(): void
    {
        Http::fake(['*/auth/qr*' => Http::response('PNGDATA', 200, ['Content-Type' => 'image/png'])]);
        $channel = Channel::factory()->create();

        $this->assertStringStartsWith(
            'data:image/png;base64,',
            (new WahaQr(new WahaClient($channel)))->dataUri()
        );
    }

    public function test_pede_codigo_de_pareamento_so_com_digitos(): void
    {
        Http::fake(['*/auth/request-code' => Http::response(['code' => 'ABCD-ABCD'], 200)]);
        $channel = Channel::factory()->create(['waha_session' => 'main']);

        $code = (new WahaClient($channel))->requestPairingCode('+55 (16) 99812-4477');

        $this->assertSame('ABCD-ABCD', $code);
        Http::assertSent(fn ($request) => $request['phoneNumber'] === '5516998124477'
            && $request->url() === 'http://waha.test:3000/api/main/auth/request-code');
    }

    public function test_presenca_offline_e_enviada_no_corpo(): void
    {
        Http::fake(['*/presence' => Http::response([], 200)]);
        $channel = Channel::factory()->create();

        (new WahaClient($channel))->setPresence('offline');

        Http::assertSent(fn ($request) => $request['presence'] === 'offline'
            && $request->url() === 'http://waha.test:3000/api/main/presence');
    }

    public function test_iniciar_registra_webhook_com_hmac_derivado_da_app_key(): void
    {
        Http::fake([
            '*/api/sessions/main' => Http::response(['message' => 'Session not found'], 404),
            '*/api/sessions' => Http::response(['status' => 'STARTING'], 201),
        ]);
        $channel = Channel::factory()->create(['waha_session' => 'main']);

        (new WahaClient($channel))->start();

        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['config']['webhooks'][0]['hmac']['key'] === WahaSettings::webhookSecret()
            && str_ends_with($request['config']['webhooks'][0]['url'], '/webhooks/waha'));
    }

    public function test_delete_session_chama_delete_na_sessao_e_aceita_404(): void
    {
        Http::fake(['*/api/sessions/main' => Http::sequence()->push([], 200)->push(['message' => 'not found'], 404)->push(['message' => 'boom'], 500)]);
        $client = new WahaClient(Channel::factory()->create(['waha_session' => 'main']));

        $client->deleteSession();
        $client->deleteSession();

        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && $request->url() === 'http://waha.test:3000/api/sessions/main');

        $this->expectException(WahaRequestException::class);
        $client->deleteSession();
    }

    public function test_qr_raw_devolve_o_valor_e_nulo_quando_falha(): void
    {
        Http::fake(['*/api/main/auth/qr*' => Http::sequence()->push(['value' => 'abc,def'], 200)->push([], 422)->push(['outro' => 'x'], 200)]);
        $qr = new WahaQr(new WahaClient(Channel::factory()->create(['waha_session' => 'main'])));

        $this->assertSame('abc,def', $qr->raw());
        Http::assertSent(fn ($request) => str_contains($request->url(), '/api/main/auth/qr') && $request['format'] === 'raw');
        $this->assertNull($qr->raw());
        $this->assertNull($qr->raw());
    }
}
