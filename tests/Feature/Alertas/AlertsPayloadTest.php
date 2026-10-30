<?php

namespace Tests\Feature\Alertas;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertsPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_pagina_entrega_o_usuario_e_so_as_preferencias_dele(): void
    {
        $eu = User::factory()->create(['alert_settings' => ['volume' => 33]]);
        User::factory()->create(['alert_settings' => ['volume' => 91]]);

        $html = (string) $this->actingAs($eu)->get(route('inbox'))->assertOk()->getContent();

        $this->assertStringContainsString('name="alert-user" content="'.$eu->id.'"', $html);
        $this->assertStringContainsString('name="alert-settings"', $html);
        $this->assertStringContainsString('&quot;volume&quot;:33', $html);
        $this->assertStringNotContainsString('&quot;volume&quot;:91', $html);
    }

    public function test_visitante_nao_recebe_dados_de_alerta_e_logado_tem_as_regioes_locais(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('alert-settings');

        $this->actingAs(User::factory()->create())->get(route('contatos'))->assertOk()
            ->assertSee('id="alerts-offline"', false)
            ->assertSee('id="alerts-audio"', false);
    }
}
