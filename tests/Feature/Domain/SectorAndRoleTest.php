<?php

namespace Tests\Feature\Domain;

use App\Enums\SectorStatus;
use App\Enums\UserRole;
use App\Models\Sector;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SectorAndRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_cria_os_cinco_setores_na_ordem_do_menu(): void
    {
        $this->seed();

        $this->assertSame(
            ['Vendas', 'Financeiro', 'Comercial', 'SAC', 'Diretoria'],
            Sector::orderBy('menu_order')->pluck('name')->all()
        );
    }

    public function test_tres_estados_controlam_menu_e_recebimento(): void
    {
        $this->assertTrue(SectorStatus::ATIVO->appearsInMenu());
        $this->assertTrue(SectorStatus::ATIVO->receivesTickets());

        $this->assertFalse(SectorStatus::FORA_DO_MENU->appearsInMenu());
        $this->assertTrue(SectorStatus::FORA_DO_MENU->receivesTickets());

        $this->assertFalse(SectorStatus::DESATIVADO->appearsInMenu());
        $this->assertFalse(SectorStatus::DESATIVADO->receivesTickets());
    }

    public function test_escopos_do_setor_respeitam_o_estado(): void
    {
        Sector::factory()->create(['name' => 'Ativo', 'status' => SectorStatus::ATIVO]);
        Sector::factory()->create(['name' => 'Oculto', 'status' => SectorStatus::FORA_DO_MENU]);
        Sector::factory()->create(['name' => 'Morto', 'status' => SectorStatus::DESATIVADO]);

        $this->assertSame(['Ativo'], Sector::visibleInMenu()->pluck('name')->all());
        $this->assertEqualsCanonicalizing(
            ['Ativo', 'Oculto'],
            Sector::receivingTickets()->pluck('name')->all()
        );
    }

    public function test_gate_admin_separa_admin_de_supervisor_e_atendente(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $supervisor = User::factory()->create(['role' => UserRole::SUPERVISOR]);
        $atendente = User::factory()->create(['role' => UserRole::ATENDENTE]);

        $this->assertTrue(Gate::forUser($admin)->allows('admin'));
        $this->assertFalse(Gate::forUser($supervisor)->allows('admin'));
        $this->assertTrue(Gate::forUser($supervisor)->allows('supervisor'));
        $this->assertFalse(Gate::forUser($atendente)->allows('supervisor'));
    }

    public function test_atendente_pode_pertencer_a_mais_de_um_setor(): void
    {
        $comercial = Sector::factory()->create(['name' => 'Comercial']);
        $vendas = Sector::factory()->create(['name' => 'Vendas']);
        $user = User::factory()->create(['role' => UserRole::ATENDENTE]);

        $user->sectors()->attach([$comercial->id, $vendas->id]);

        $this->assertCount(2, $user->fresh()->sectors);
    }

    /** @return array<string, array{UserRole}> */
    public static function papeisDeTeste(): array
    {
        return [
            'supervisor' => [UserRole::SUPERVISOR],
            'atendente' => [UserRole::ATENDENTE],
        ];
    }

    #[DataProvider('papeisDeTeste')]
    public function test_usuario_inativo_nao_consegue_entrar(UserRole $role): void
    {
        $user = User::factory()->create([
            'role' => $role,
            'password' => 'senha-forte',
            'active' => false,
        ]);

        $this->post('/login', ['email' => $user->email, 'password' => 'senha-forte']);

        $this->assertGuest();
    }
}
