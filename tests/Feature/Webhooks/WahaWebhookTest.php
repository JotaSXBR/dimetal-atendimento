<?php

namespace Tests\Feature\Webhooks;

use App\Jobs\HandleWahaEvent;
use App\Models\Channel;
use App\Models\Setting;
use App\Models\WahaEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WahaWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::put('waha.webhook_secret', 'segredo', 'secret');
        Queue::fake();
    }

    /** @param array<string, mixed> $overrides */
    private function sendEvent(array $overrides = [], ?string $secret = 'segredo'): TestResponse
    {
        $body = array_merge([
            'id' => 'evt_01',
            'event' => 'session.status',
            'session' => 'main',
            'engine' => 'GOWS',
            'payload' => ['status' => 'WORKING'],
        ], $overrides);

        $json = json_encode($body, JSON_UNESCAPED_UNICODE);
        $headers = ['CONTENT_TYPE' => 'application/json'];

        if ($secret !== null) {
            $headers['HTTP_X_WEBHOOK_HMAC'] = hash_hmac('sha512', $json, $secret);
            $headers['HTTP_X_WEBHOOK_HMAC_ALGORITHM'] = 'sha512';
        }

        return $this->call('POST', '/webhooks/waha', [], [], [], $headers, $json);
    }

    public function test_sem_hmac_nao_grava_nada(): void
    {
        $this->sendEvent(secret: null)->assertUnauthorized();

        $this->assertSame(0, WahaEvent::count());
        Queue::assertNothingPushed();
    }

    public function test_hmac_de_outro_corpo_nao_autoriza(): void
    {
        $json = '{"id":"evt_01","event":"session.status","session":"main"}';

        $this->call('POST', '/webhooks/waha', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_WEBHOOK_HMAC' => hash_hmac('sha512', 'outro-corpo', 'segredo'),
        ], $json)->assertUnauthorized();

        $this->assertSame(0, WahaEvent::count());
    }

    public function test_grava_o_evento_enfileira_e_responde_rapido(): void
    {
        Channel::factory()->create(['waha_session' => 'main']);

        $this->sendEvent()->assertOk()->assertExactJson(['ok' => true]);

        $this->assertSame('session.status', WahaEvent::first()->event);
        Queue::assertPushed(HandleWahaEvent::class);
    }

    public function test_evento_repetido_nao_duplica(): void
    {
        Channel::factory()->create(['waha_session' => 'main']);

        $this->sendEvent()->assertOk();
        $this->sendEvent()->assertOk();

        $this->assertSame(1, WahaEvent::count());
    }

    public function test_sessao_desconhecida_nao_quebra(): void
    {
        $this->sendEvent(['session' => 'nao-existe'])->assertOk();

        $this->assertNull(WahaEvent::first()->channel_id);
    }

    public function test_texto_com_acento_e_emoji_fica_intacto(): void
    {
        $this->sendEvent([
            'event' => 'message',
            'payload' => ['body' => 'Orçamento da chapa 🚀 ação'],
        ])->assertOk();

        $this->assertSame(
            'Orçamento da chapa 🚀 ação',
            WahaEvent::first()->payload['payload']['body']
        );
    }
}
