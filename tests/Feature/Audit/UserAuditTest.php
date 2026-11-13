<?php

namespace Tests\Feature\Audit;

use App\Enums\UserRole;
use App\Livewire\Settings\Users;
use App\Models\AuditLog;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UserAuditTest extends TestCase
{
    use RefreshDatabase;

    private function as(UserRole $role): User
    {
        return User::factory()->create(['role' => $role]);
    }

    /** @return list<string> */
    private function actions(): array
    {
        return AuditLog::query()->orderBy('id')->pluck('action')->all();
    }

    public function test_criar_grava_so_o_user_created_com_o_papel_e_sem_a_senha(): void
    {
        $admin = $this->as(UserRole::ADMIN);

        Livewire::actingAs($admin)->test(Users::class)
            ->call('newUser')
            ->set('name', 'Beto Souza')->set('email', 'beto@dimetal.test')->set('role', 'supervisor')->set('password', 'senha-inicial-123')
            ->call('save')->assertHasNoErrors();

        $log = AuditLog::query()->sole();
        $this->assertSame('user.created', $log->action);
        $this->assertSame('Beto Souza', $log->subject);
        $this->assertSame(['role' => 'supervisor'], $log->detail);
        $this->assertSame($admin->id, $log->actor_user_id);
        $this->assertStringNotContainsString('senha-inicial-123', (string) json_encode(AuditLog::query()->get()->toArray()));
    }

    public function test_editar_o_nome_grava_user_updated_com_o_campo(): void
    {
        $target = $this->as(UserRole::ATENDENTE);

        Livewire::actingAs($this->as(UserRole::ADMIN))->test(Users::class)
            ->call('edit', $target->id)->set('name', 'Nome Novo')->call('save')->assertHasNoErrors();

        $log = AuditLog::query()->sole();
        $this->assertSame('user.updated', $log->action);
        $this->assertSame('Nome Novo', $log->subject);
        $this->assertSame(['fields' => ['name']], $log->detail);
    }

    public function test_mudar_setores_grava_user_updated_com_setores(): void
    {
        $target = $this->as(UserRole::ATENDENTE);
        $sector = Sector::factory()->create();

        Livewire::actingAs($this->as(UserRole::ADMIN))->test(Users::class)
            ->call('edit', $target->id)->set('sectorIds', [$sector->id])->call('save')->assertHasNoErrors();

        $this->assertSame(['fields' => ['sectors']], AuditLog::query()->sole()->detail);
    }

    public function test_salvar_sem_mudar_nada_nao_grava(): void
    {
        $target = $this->as(UserRole::ATENDENTE);

        Livewire::actingAs($this->as(UserRole::ADMIN))->test(Users::class)->call('edit', $target->id)->call('save')->assertHasNoErrors();

        $this->assertSame([], $this->actions());
    }

    public function test_mudar_o_papel_grava_user_role_changed_com_de_e_para(): void
    {
        $target = $this->as(UserRole::ATENDENTE);

        Livewire::actingAs($this->as(UserRole::ADMIN))->test(Users::class)
            ->call('edit', $target->id)->set('role', 'supervisor')->call('save')->assertHasNoErrors();

        $log = AuditLog::query()->sole();
        $this->assertSame('user.role_changed', $log->action);
        $this->assertSame(['from' => 'atendente', 'to' => 'supervisor'], $log->detail);
    }

    public function test_definir_a_senha_grava_password_set_e_nunca_a_senha(): void
    {
        $target = $this->as(UserRole::ATENDENTE);

        Livewire::actingAs($this->as(UserRole::ADMIN))->test(Users::class)
            ->call('edit', $target->id)->set('password', 'outra-senha-segura-456')->call('save')->assertHasNoErrors();

        $log = AuditLog::query()->sole();
        $this->assertSame('user.password_set', $log->action);
        $this->assertNull($log->detail);
        $this->assertStringNotContainsString('outra-senha-segura-456', (string) json_encode(AuditLog::query()->get()->toArray()));
    }

    public function test_mudar_papel_e_nome_juntos_gravam_as_duas_acoes(): void
    {
        $target = $this->as(UserRole::ATENDENTE);

        Livewire::actingAs($this->as(UserRole::ADMIN))->test(Users::class)
            ->call('edit', $target->id)->set('name', 'Nome Novo')->set('role', 'supervisor')->call('save')->assertHasNoErrors();

        $this->assertEqualsCanonicalizing(['user.updated', 'user.role_changed'], $this->actions());
    }

    public function test_desativar_e_reativar_gravam(): void
    {
        $admin = $this->as(UserRole::ADMIN);
        $target = $this->as(UserRole::ATENDENTE);

        Livewire::actingAs($admin)->test(Users::class)->call('toggleActive', $target->id)->call('toggleActive', $target->id);

        $this->assertSame(['user.deactivated', 'user.activated'], $this->actions());
        $this->assertSame($target->name, AuditLog::query()->firstOrFail()->subject);
    }

    public function test_o_ator_e_quem_esta_logado_e_nao_o_alvo(): void
    {
        $supervisor = $this->as(UserRole::SUPERVISOR);
        $target = $this->as(UserRole::ATENDENTE);

        Livewire::actingAs($supervisor)->test(Users::class)->call('edit', $target->id)->set('name', 'Outro Nome')->call('save');

        $this->assertSame($supervisor->id, AuditLog::query()->sole()->actor_user_id);
    }

    public function test_acao_negada_nao_grava(): void
    {
        $admin = $this->as(UserRole::ADMIN);

        Livewire::actingAs($this->as(UserRole::SUPERVISOR))->test(Users::class)->call('toggleActive', $admin->id)->assertForbidden();

        $this->assertSame([], $this->actions());
    }
}
