<?php

namespace Tests\Feature\Layout;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SettingsPageTest extends TestCase
{
    use RefreshDatabase;

    private function page(UserRole $role, string $url): string
    {
        return (string) $this->actingAs(User::factory()->create(['role' => $role]))->get($url)->assertOk()->getContent();
    }

    public function test_admin_ve_o_caminho_os_irmaos_e_a_pagina_atual_marcada(): void
    {
        $html = $this->page(UserRole::ADMIN, '/configuracoes/setores');

        $this->assertStringContainsString('Configuração › Atendimento', $html);
        $this->assertStringContainsString('aria-label="Páginas de Atendimento"', $html);
        $this->assertStringContainsString(route('configuracoes.horario'), $html);
        $this->assertStringContainsString(route('configuracoes.respostas'), $html);
        $this->assertMatchesRegularExpression('/aria-current="page"[^>]*>\s*Setores/', $html);
    }

    public function test_grupo_com_um_item_visivel_nao_mostra_a_linha_de_irmaos(): void
    {
        $this->assertStringNotContainsString('aria-label="Páginas de', $this->page(UserRole::SUPERVISOR, '/configuracoes/respostas-rapidas'));
        $this->assertStringNotContainsString('aria-label="Páginas de', $this->page(UserRole::ADMIN, '/configuracoes/usuarios'));
    }

    public function test_todas_as_paginas_de_configuracao_usam_o_cabecalho_comum(): void
    {
        foreach (['setores', 'horario', 'respostas-rapidas', 'usuarios', 'canais', 'google', 'empresa', 'retencao'] as $page) {
            $this->assertStringContainsString('Configuração ›', $this->page(UserRole::ADMIN, '/configuracoes/'.$page), $page);
        }
    }

    public function test_canais_e_horario_nao_mostram_o_menu_pessoal(): void
    {
        foreach (['canais', 'horario'] as $page) {
            $html = $this->page(UserRole::ADMIN, '/configuracoes/'.$page);

            foreach (['Gerencie seu perfil', 'Manage your profile', route('security.edit')] as $personal) {
                $this->assertStringNotContainsString($personal, $html, $page);
            }
        }
    }
}
