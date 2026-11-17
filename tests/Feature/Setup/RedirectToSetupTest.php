<?php

namespace Tests\Feature\Setup;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RedirectToSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_sem_admin_raiz_e_login_vao_para_instalar(): void
    {
        $this->startFreshInstall();

        $this->get('/')->assertRedirect('/instalar');
        $this->get('/login')->assertRedirect('/instalar');
    }

    public function test_admin_logado_vai_para_primeiros_passos(): void
    {
        $this->startFreshInstall();
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);

        $this->actingAs($admin)->get('/inbox')->assertRedirect('/primeiros-passos');
    }

    public function test_atendente_nao_e_redirecionado(): void
    {
        $this->startFreshInstall();
        User::factory()->create(['role' => UserRole::ADMIN]);
        $atendente = User::factory()->create();

        $this->actingAs($atendente)->get('/inbox')->assertOk();
    }

    public function test_webhook_e_logo_passam_durante_a_configuracao(): void
    {
        $this->startFreshInstall();

        $this->assertNotSame(302, $this->post('/webhooks/waha')->getStatusCode());
        $this->assertNotSame(302, $this->get('/marca/logo')->getStatusCode());
    }

    public function test_instalacao_concluida_nao_redireciona(): void
    {
        $this->get('/login')->assertOk();
    }
}
