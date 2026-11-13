<?php

namespace Tests\Feature\Services;

use App\Services\Waha\WebhookSignature;
use App\Settings\WahaSettings;
use Illuminate\Http\Request;
use Tests\TestCase;

class WebhookSignatureTest extends TestCase
{
    private const BODY = '{"id":"evt_01","event":"session.status"}';

    /** @param  array<string, string>  $headers */
    private function request(string $body = self::BODY, array $headers = []): Request
    {
        return Request::create('/webhooks/waha', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', ...$headers], $body);
    }

    private function hmac(string $body = self::BODY): string
    {
        return hash_hmac('sha512', $body, WahaSettings::webhookSecret());
    }

    public function test_assinatura_do_corpo_com_o_segredo_e_valida(): void
    {
        $this->assertTrue(WebhookSignature::valid($this->request(headers: ['HTTP_X_WEBHOOK_HMAC' => $this->hmac()])));
        $this->assertTrue(WebhookSignature::valid($this->request(headers: ['HTTP_X_WEBHOOK_HMAC' => strtoupper($this->hmac()), 'HTTP_X_WEBHOOK_HMAC_ALGORITHM' => 'SHA512'])));
    }

    public function test_assinatura_de_outro_corpo_e_invalida(): void
    {
        $this->assertFalse(WebhookSignature::valid($this->request(headers: ['HTTP_X_WEBHOOK_HMAC' => $this->hmac('{"outro":1}')])));
    }

    public function test_sem_cabecalho_e_invalida(): void
    {
        $this->assertFalse(WebhookSignature::valid($this->request()));
    }

    public function test_algoritmo_diferente_de_sha512_e_invalido(): void
    {
        $this->assertFalse(WebhookSignature::valid($this->request(headers: ['HTTP_X_WEBHOOK_HMAC' => $this->hmac(), 'HTTP_X_WEBHOOK_HMAC_ALGORITHM' => 'sha256'])));
    }

    public function test_o_resultado_fica_guardado_na_requisicao_e_o_hash_roda_uma_vez(): void
    {
        $request = $this->request(headers: ['HTTP_X_WEBHOOK_HMAC' => $this->hmac()]);

        $this->assertTrue(WebhookSignature::valid($request));

        // O limitador e o controller recebem a mesma requisição: a segunda pergunta vem do que ficou guardado, não de novo cálculo.
        $request->headers->remove('X-Webhook-Hmac');
        $this->assertTrue(WebhookSignature::valid($request));
    }

    public function test_segredo_vazio_e_invalido(): void
    {
        $this->assertFalse(WebhookSignature::valid($this->request(headers: ['HTTP_X_WEBHOOK_HMAC' => $this->hmac()]), ''));
    }
}
