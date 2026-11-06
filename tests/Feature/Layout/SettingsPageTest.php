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

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($dom);
    }

    /** @return list<string> os títulos de grupo da navegação de Configuração */
    private function groupTitles(\DOMXPath $xpath): array
    {
        $titles = [];
        foreach ($xpath->query('//nav[@aria-label="Configuração"]//*[@data-test="settings-group-title"]') as $node) {
            $titles[] = trim($node->textContent);
        }

        return $titles;
    }

    public function test_admin_ve_a_navegacao_de_configuracao_com_a_pagina_atual_marcada(): void
    {
        $html = $this->page(UserRole::ADMIN, '/configuracoes/setores');
        $xpath = $this->xpath($html);

        $this->assertSame(['Atendimento', 'Equipe', 'Conexões', 'Empresa'], $this->groupTitles($xpath));
        $this->assertSame(1, $xpath->query('//nav[@aria-label="Configuração"]//a[@href="'.route('configuracoes.horario').'"]')->length);
        $current = $xpath->query('//nav[@aria-label="Configuração"]//a[@aria-current="page"]');
        $this->assertSame(1, $current->length);
        $this->assertSame('Setores', trim($current->item(0)?->textContent ?? ''));
        $this->assertStringNotContainsString('Configuração ›', $html);
        $this->assertStringNotContainsString('Páginas de', $html);
    }

    public function test_supervisor_so_ve_os_grupos_que_pode_abrir(): void
    {
        $xpath = $this->xpath($this->page(UserRole::SUPERVISOR, '/configuracoes/usuarios'));

        $this->assertSame(['Atendimento', 'Equipe'], $this->groupTitles($xpath));
    }

    public function test_todas_as_paginas_de_configuracao_usam_a_navegacao_comum(): void
    {
        foreach (['setores', 'horario', 'respostas-rapidas', 'etiquetas', 'usuarios', 'canais', 'google', 'empresa', 'retencao'] as $page) {
            $xpath = $this->xpath($this->page(UserRole::ADMIN, '/configuracoes/'.$page));

            $this->assertSame(1, $xpath->query('//nav[@aria-label="Configuração"]')->length, $page);
        }
    }

    public function test_atendente_continua_barrado(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::ATENDENTE]))->get('/configuracoes/setores')->assertForbidden();
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
