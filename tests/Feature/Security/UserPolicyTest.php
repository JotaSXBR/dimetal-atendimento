<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserPolicyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{UserRole, UserRole, bool}> ator, alvo, pode editar/desativar */
    public static function matriz(): array
    {
        return [
            'admin edita admin' => [UserRole::ADMIN, UserRole::ADMIN, true],
            'admin edita supervisor' => [UserRole::ADMIN, UserRole::SUPERVISOR, true],
            'admin edita atendente' => [UserRole::ADMIN, UserRole::ATENDENTE, true],
            'supervisor edita admin' => [UserRole::SUPERVISOR, UserRole::ADMIN, false],
            'supervisor edita supervisor' => [UserRole::SUPERVISOR, UserRole::SUPERVISOR, false],
            'supervisor edita atendente' => [UserRole::SUPERVISOR, UserRole::ATENDENTE, true],
            'atendente edita admin' => [UserRole::ATENDENTE, UserRole::ADMIN, false],
            'atendente edita supervisor' => [UserRole::ATENDENTE, UserRole::SUPERVISOR, false],
            'atendente edita atendente' => [UserRole::ATENDENTE, UserRole::ATENDENTE, false],
        ];
    }

    #[DataProvider('matriz')]
    public function test_editar_segue_o_papel_do_alvo(UserRole $actor, UserRole $target, bool $allowed): void
    {
        $actorUser = User::factory()->create(['role' => $actor]);
        $targetUser = User::factory()->create(['role' => $target]);

        $this->assertSame($allowed, $actorUser->can('update', $targetUser));
        $this->assertSame($allowed, $actorUser->can('deactivate', $targetUser));
    }

    #[DataProvider('matriz')]
    public function test_atribuir_papel_segue_a_mesma_regra(UserRole $actor, UserRole $role, bool $allowed): void
    {
        $actorUser = User::factory()->create(['role' => $actor]);

        $this->assertSame($allowed, $actorUser->can('create', [User::class, $role]));
        $this->assertSame($allowed, $actorUser->can('assignRole', [User::class, $role]));
    }

    public function test_so_admin_e_supervisor_veem_a_lista(): void
    {
        $this->assertTrue(User::factory()->create(['role' => UserRole::ADMIN])->can('viewAny', User::class));
        $this->assertTrue(User::factory()->create(['role' => UserRole::SUPERVISOR])->can('viewAny', User::class));
        $this->assertFalse(User::factory()->create(['role' => UserRole::ATENDENTE])->can('viewAny', User::class));
    }

    public function test_ninguem_desativa_a_si_mesmo(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        User::factory()->create(['role' => UserRole::ADMIN]);

        $this->assertTrue($admin->can('update', $admin));
        $this->assertFalse($admin->can('deactivate', $admin));
    }

    public function test_usuario_inativo_nao_administra_ninguem(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN, 'active' => false]);

        $this->assertFalse($admin->can('viewAny', User::class));
        $this->assertFalse($admin->can('update', User::factory()->create()));
    }

    public function test_reconhece_o_ultimo_admin_ativo(): void
    {
        $only = User::factory()->create(['role' => UserRole::ADMIN]);
        User::factory()->create(['role' => UserRole::ADMIN, 'active' => false]);

        $this->assertTrue($only->isLastActiveAdmin());

        $other = User::factory()->create(['role' => UserRole::ADMIN]);

        $this->assertFalse($only->isLastActiveAdmin());
        $this->assertFalse($other->isLastActiveAdmin());
        $this->assertFalse(User::factory()->create(['role' => UserRole::SUPERVISOR])->isLastActiveAdmin());
    }
}
