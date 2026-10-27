<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

class LoginScreenTest extends TestCase
{
    public function test_login_interno_mostra_a_marca_e_nao_oferece_cadastro(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Dimetal Distribuidora')
            ->assertSee('Entrar')
            ->assertDontSee('Criar conta')
            ->assertDontSee('Esqueci minha senha');
    }

    public function test_cadastro_publico_nao_existe(): void
    {
        $this->get('/register')->assertNotFound();
    }
}
