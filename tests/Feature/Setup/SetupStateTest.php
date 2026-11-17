<?php

namespace Tests\Feature\Setup;

use App\Models\Sector;
use App\Models\Setting;
use App\Models\Tag;
use App\Models\User;
use App\Services\Setup\SetupState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class SetupStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_banco_novo_nao_esta_concluido_e_comeca_na_empresa(): void
    {
        $this->startFreshInstall();

        $this->assertFalse(SetupState::concluded());
        $this->assertSame('empresa', SetupState::step());
    }

    public function test_marcar_concluido_vale_para_sempre(): void
    {
        $this->startFreshInstall();

        SetupState::markConcluded();
        Setting::query()->delete();

        $this->assertTrue(SetupState::concluded());
    }

    public function test_passo_invalido_e_recusado(): void
    {
        $this->expectException(InvalidArgumentException::class);

        SetupState::setStep('qualquer');
    }

    public function test_passo_e_segmento_voltam_o_que_foi_gravado(): void
    {
        SetupState::setStep('canal');
        SetupState::setSegment('distribuidora');

        $this->assertSame('canal', SetupState::step());
        $this->assertSame('distribuidora', SetupState::segment());
    }

    public function test_has_admin_ignora_atendente_e_supervisor(): void
    {
        User::factory()->create(['role' => 'atendente']);
        User::factory()->create(['role' => 'supervisor']);
        $this->assertFalse(SetupState::hasAdmin());

        User::factory()->create(['role' => 'admin', 'active' => false]);
        $this->assertTrue(SetupState::hasAdmin());
    }

    public function test_migracao_marca_instalacao_com_usuario_e_instala_padroes_que_faltam(): void
    {
        $this->startFreshInstall();
        User::factory()->create(['role' => 'atendente']);

        $this->migracao()->up();

        $this->assertTrue(SetupState::concluded());
        $this->assertSame(5, Sector::query()->count());
        $this->assertSame(15, Tag::query()->count());
    }

    public function test_migracao_deixa_banco_vazio_sem_marca(): void
    {
        $this->startFreshInstall();

        $this->migracao()->up();

        $this->assertFalse(SetupState::concluded());
        $this->assertSame(0, Sector::query()->count());
    }

    private function migracao(): object
    {
        return require database_path('migrations/2026_11_17_000001_mark_existing_installs_as_set_up.php');
    }
}
