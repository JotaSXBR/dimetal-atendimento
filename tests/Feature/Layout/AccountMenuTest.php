<?php

namespace Tests\Feature\Layout;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountMenuTest extends TestCase
{
    use RefreshDatabase;

    private function html(UserRole $role, string $url): string
    {
        return (string) $this->actingAs(User::factory()->create(['role' => $role]))->get($url)->assertOk()->getContent();
    }

    public function test_paginas_pessoais_mostram_minha_conta_com_as_quatro_abas_em_portugues(): void
    {
        foreach (UserRole::cases() as $role) {
            $html = $this->html($role, route('profile.edit', absolute: false));

            foreach (['Minha conta', 'Perfil', 'Segurança', 'Aparência', 'Meus alertas'] as $text) {
                $this->assertStringContainsString($text, $html, $role->value.': '.$text);
            }
            $this->assertStringNotContainsString('Manage your profile and account settings', $html);
        }
    }

    public function test_menu_do_avatar_diz_minha_conta_e_sair(): void
    {
        $html = $this->html(UserRole::ATENDENTE, route('inbox', absolute: false));

        $this->assertStringContainsString('Minha conta', $html);
        $this->assertStringContainsString('Sair', $html);
        $this->assertStringNotContainsString('Log out', $html);
    }

    public function test_cada_pagina_pessoal_mantem_a_url(): void
    {
        foreach (['profile.edit' => '/settings/profile', 'appearance.edit' => '/settings/appearance', 'alerts.edit' => '/settings/alerts'] as $name => $path) {
            $this->assertSame($path, route($name, absolute: false));
        }
    }
}
