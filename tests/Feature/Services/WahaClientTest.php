<?php

namespace Tests\Feature\Services;

use App\Models\Channel;
use App\Services\Waha\WahaClient;
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
            (new WahaClient($channel))->qrDataUri()
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
}
