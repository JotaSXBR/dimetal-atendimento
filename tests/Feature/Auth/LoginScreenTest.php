<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Settings\CompanySettings;
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

    public function test_login_no_desenho(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('data-auth-brand', false)
            ->assertSee(CompanySettings::name())
            ->assertSee('data-auth-card', false)
            ->assertSee('data-flux-input', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false)
            ->assertSee('Manter conectado')
            ->assertSee('data-test="login-button"', false)
            ->assertSeeInOrder(['data-auth-help', 'Sem acesso?', 'O acesso é criado pelo administrador. Precisa de um novo? Fale com Rafael Costa.'], false)
            ->assertSeeInOrder(['data-auth-footer', 'Todo acesso é registrado na auditoria'], false)
            ->assertDontSee('Esqueci');
    }

    public function test_login_com_erro_marca_o_campo(): void
    {
        $user = User::factory()->create();

        $this->from('/login')
            ->followingRedirects()
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'senha-errada'])
            ->assertOk()
            ->assertSee(__('auth.failed'))
            ->assertSee('aria-invalid="true"', false);
    }

    public function test_cadastro_publico_nao_existe(): void
    {
        $this->get('/register')->assertNotFound();
    }
}
