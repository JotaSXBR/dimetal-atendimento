<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_o_usuario_ativo_com_a_senha_gravada_como_hash(): void
    {
        $this->artisan('app:create-user', ['email' => 'ana@example.com', 'name' => 'Ana', '--role' => 'supervisor'])
            ->expectsQuestion('Senha', 'senha-segura-123')
            ->expectsOutput('Usuário ana@example.com salvo como Supervisor.')
            ->assertSuccessful();

        $user = User::where('email', 'ana@example.com')->sole();

        $this->assertSame(UserRole::SUPERVISOR, $user->role);
        $this->assertTrue($user->active);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNotSame('senha-segura-123', $user->password);
        $this->assertTrue(Hash::check('senha-segura-123', $user->password));
    }

    public function test_o_papel_padrao_e_atendente(): void
    {
        $this->artisan('app:create-user', ['email' => 'bia@example.com', 'name' => 'Bia'])
            ->expectsQuestion('Senha', 'senha-segura-123')
            ->assertSuccessful();

        $this->assertSame(UserRole::ATENDENTE, User::where('email', 'bia@example.com')->sole()->role);
    }

    public function test_rodar_de_novo_atualiza_o_mesmo_usuario_e_reativa(): void
    {
        User::factory()->create(['email' => 'ana@example.com', 'role' => UserRole::ATENDENTE, 'active' => false]);

        $this->artisan('app:create-user', ['email' => 'ana@example.com', 'name' => 'Ana Nova', '--role' => 'admin'])
            ->expectsQuestion('Senha', 'outra-senha-123')
            ->assertSuccessful();

        $this->assertSame(1, User::where('email', 'ana@example.com')->count());
        $user = User::where('email', 'ana@example.com')->sole();
        $this->assertSame('Ana Nova', $user->name);
        $this->assertSame(UserRole::ADMIN, $user->role);
        $this->assertTrue($user->active);
        $this->assertTrue(Hash::check('outra-senha-123', $user->password));
    }

    public function test_recusa_papel_invalido_sem_criar_nada(): void
    {
        $this->artisan('app:create-user', ['email' => 'x@example.com', 'name' => 'X', '--role' => 'root'])->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_recusa_email_invalido(): void
    {
        $this->artisan('app:create-user', ['email' => 'nao-e-email', 'name' => 'X'])->assertFailed();

        $this->assertSame(0, User::count());
    }

    public function test_recusa_senha_curta_e_nao_cria_o_usuario(): void
    {
        $this->artisan('app:create-user', ['email' => 'x@example.com', 'name' => 'X'])
            ->expectsQuestion('Senha', '1234567')
            ->expectsOutput('A senha precisa ter ao menos 8 caracteres.')
            ->assertFailed();

        $this->assertSame(0, User::count());
    }
}
