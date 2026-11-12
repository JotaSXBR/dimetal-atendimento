<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

class SecurityHeadersTest extends TestCase
{
    public function test_envia_os_cabecalhos_que_o_servidor_web_nao_envia(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('Permissions-Policy', 'camera=(), microphone=(self), geolocation=(), payment=(), usb=()');
        $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $response->assertHeader('Cross-Origin-Resource-Policy', 'same-origin');
    }

    public function test_csp_vem_em_enforce_por_padrao(): void
    {
        $response = $this->get('/login');

        $response->assertHeader('Content-Security-Policy');
        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    public function test_csp_somente_relatorio_usa_o_cabecalho_que_nao_bloqueia(): void
    {
        config(['security.csp_mode' => 'report-only']);

        $response = $this->get('/login');

        $response->assertHeader('Content-Security-Policy-Report-Only');
        $response->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_csp_desligada_nao_envia_o_cabecalho(): void
    {
        config(['security.csp_mode' => 'off']);

        $response = $this->get('/login');

        $response->assertHeaderMissing('Content-Security-Policy');
        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    public function test_a_politica_so_aceita_o_proprio_dominio_e_proibe_objetos_e_frames_externos(): void
    {
        $policy = (string) $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("default-src 'self'", $policy);
        $this->assertStringContainsString("script-src 'self'", $policy);
        $this->assertStringContainsString("object-src 'none'", $policy);
        $this->assertStringContainsString("base-uri 'self'", $policy);
        $this->assertStringContainsString("form-action 'self'", $policy);
        $this->assertStringContainsString("frame-ancestors 'self'", $policy);
    }

    private function scriptSrc(string $policy): string
    {
        $this->assertSame(1, preg_match('/script-src ([^;]*)/', $policy, $m));

        return $m[1];
    }

    public function test_script_src_tem_o_hash_do_script_de_aparencia_que_a_pagina_renderiza(): void
    {
        $response = $this->get('/login');

        // O único <script> sem src da página é o `@fluxAppearance`; o hash do cabeçalho tem de ser o dele, senão o enforce bloqueia o tema.
        $this->assertSame(1, preg_match_all('/<script(?![^>]*\ssrc=)[^>]*>(.*?)<\/script>/s', (string) $response->getContent(), $m));
        $hash = 'sha256-'.base64_encode(hash('sha256', $m[1][0], true));

        $this->assertStringContainsString("'{$hash}'", $this->scriptSrc((string) $response->headers->get('Content-Security-Policy')));
    }

    public function test_politica_libera_blob_so_para_midia_e_nao_libera_inline_em_script(): void
    {
        $policy = (string) $this->get('/login')->headers->get('Content-Security-Policy');
        $scriptSrc = $this->scriptSrc($policy);

        $this->assertStringContainsString("media-src 'self' blob:", $policy);
        $this->assertStringNotContainsString("'unsafe-eval'", $scriptSrc); // o build CSP do Alpine não avalia strings
        $this->assertStringNotContainsString("'unsafe-inline'", $scriptSrc);
        $this->assertStringNotContainsString('blob:', $scriptSrc);
    }

    public function test_resposta_404_leva_os_mesmos_cabecalhos_de_seguranca(): void
    {
        $response = $this->get('/rota-que-nao-existe')->assertNotFound();

        $response->assertHeader('Permissions-Policy');
        $response->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
        $response->assertHeader('Content-Security-Policy');
    }

    public function test_sem_unsafe_eval_por_padrao_e_so_com_o_build_alpine_normal(): void
    {
        $this->assertStringNotContainsString("'unsafe-eval'", $this->scriptSrc((string) $this->get('/login')->headers->get('Content-Security-Policy')));

        config(['livewire.csp_safe' => false]);

        $this->assertStringContainsString("'unsafe-eval'", $this->scriptSrc((string) $this->get('/login')->headers->get('Content-Security-Policy')));
    }

    // O Livewire imprime o <script> uma vez por processo (flag estática): um teste por estado.
    public function test_livewire_serve_o_build_csp_por_padrao(): void
    {
        $this->assertMatchesRegularExpression('/livewire\.js\?id=[^"]*-csp/', (string) $this->get('/login')->getContent());
    }

    public function test_livewire_serve_o_build_normal_quando_a_chave_esta_desligada(): void
    {
        config(['livewire.csp_safe' => false]);

        $this->assertDoesNotMatchRegularExpression('/livewire\.js\?id=[^"]*-csp/', (string) $this->get('/login')->getContent());
    }

    public function test_upgrade_insecure_requests_so_em_https(): void
    {
        $this->assertStringNotContainsString('upgrade-insecure-requests', (string) $this->get('http://localhost/login')->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('upgrade-insecure-requests', (string) $this->get('https://localhost/login')->headers->get('Content-Security-Policy'));
    }

    public function test_websocket_do_reverb_e_o_mesmo_dominio_e_segue_o_protocolo_da_pagina(): void
    {
        // O .env de desenvolvimento define a URL pública do Reverb; sem ela, vale o domínio da própria página.
        config(['broadcasting.connections.reverb.public_url' => null]);

        $this->assertStringContainsString("connect-src 'self' ws://localhost", (string) $this->get('http://localhost/login')->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString("connect-src 'self' wss://app.example.com", (string) $this->get('https://app.example.com/login')->headers->get('Content-Security-Policy'));
    }

    public function test_websocket_de_desenvolvimento_vem_da_url_publica_do_reverb(): void
    {
        config(['broadcasting.connections.reverb.public_url' => 'http://localhost:8080']);

        $policy = (string) $this->get('/login')->headers->get('Content-Security-Policy');

        $this->assertStringContainsString("connect-src 'self' ws://localhost:8080", $policy);
    }
}
