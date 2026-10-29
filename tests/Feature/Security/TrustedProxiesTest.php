<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/_cliente', fn () => request()->ip().'|'.(request()->isSecure() ? 'https' : 'http'));
    }

    /**
     * @return array<string, string>
     */
    private function forwarded(string $from): array
    {
        return ['REMOTE_ADDR' => $from, 'HTTP_X_FORWARDED_FOR' => '203.0.113.9', 'HTTP_X_FORWARDED_PROTO' => 'https'];
    }

    public function test_aceita_ip_e_protocolo_vindos_do_proxy_da_rede_privada(): void
    {
        foreach (['10.0.1.6', '172.18.0.4', '192.168.1.10', 'fd11:ef4:2f91::11'] as $proxy) {
            $this->call('GET', '/_cliente', [], [], [], $this->forwarded($proxy))
                ->assertOk()
                ->assertContent('203.0.113.9|https');
        }
    }

    public function test_ignora_x_forwarded_de_quem_chega_de_fora_da_rede_privada(): void
    {
        // Sem isso, qualquer um forjaria o próprio IP e escaparia do limite de tentativas do login.
        $this->call('GET', '/_cliente', [], [], [], $this->forwarded('8.8.8.8'))
            ->assertOk()
            ->assertContent('8.8.8.8|http');
    }
}
