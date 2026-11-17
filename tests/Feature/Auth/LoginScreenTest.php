<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_interno_mostra_a_marca_e_nao_oferece_cadastro(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Fluxie Inbox')
            ->assertSee('Entrar')
            ->assertDontSee('Criar conta')
            ->assertDontSee('Esqueci minha senha');
    }

    public function test_cadastro_publico_nao_existe(): void
    {
        $this->get('/register')->assertNotFound();
    }
}
