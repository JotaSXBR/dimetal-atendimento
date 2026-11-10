<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyNameUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_titulo_e_meta_usam_o_nome_da_empresa(): void
    {
        Setting::put('company.name', 'Metalúrgica Teste');

        $this->get('/login')
            ->assertOk()
            ->assertSee('Metalúrgica Teste')
            ->assertSee('Atendimento via WhatsApp da Metalúrgica Teste', false);
    }

    public function test_menu_lateral_mostra_o_nome_da_empresa(): void
    {
        Setting::put('company.name', 'Metalúrgica Teste');

        $this->actingAs(User::factory()->create())->get('/inbox')
            ->assertOk()
            ->assertSee('Metalúrgica Teste');
    }

    public function test_sem_nome_cadastrado_cai_no_app_name(): void
    {
        config(['app.name' => 'AppFallback']);

        $this->get('/login')->assertOk()->assertSee('AppFallback');
    }
}
