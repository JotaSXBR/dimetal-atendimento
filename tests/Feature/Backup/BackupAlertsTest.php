<?php

namespace Tests\Feature\Backup;

use App\Enums\UserRole;
use App\Livewire\Alerts\Bell;
use App\Models\User;
use App\Notifications\BackupFailed;
use App\Notifications\BackupStale;
use App\Services\Alerts\AlertRecipients;
use App\Settings\BackupStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BackupAlertsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admins_so_admin_ativo(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN, 'active' => true]);
        User::factory()->create(['role' => UserRole::ADMIN, 'active' => false]);
        User::factory()->create(['role' => UserRole::SUPERVISOR]);
        User::factory()->create(['role' => UserRole::ATENDENTE]);

        $ids = app(AlertRecipients::class)->admins()->pluck('id')->all();

        $this->assertSame([$admin->id], $ids);
    }

    public function test_status_grava_sucesso_e_erro_e_o_sucesso_zera_o_erro(): void
    {
        $this->assertNull(BackupStatus::lastSuccessAt());
        $this->assertNull(BackupStatus::lastError());

        BackupStatus::recordFailure('pg_dump saiu com código 1');
        $this->assertSame('pg_dump saiu com código 1', BackupStatus::lastError());

        $this->travelTo('2026-11-13 05:15:00');
        BackupStatus::recordSuccess();

        $this->assertNull(BackupStatus::lastError());
        $this->assertSame('2026-11-13 05:15:00', BackupStatus::lastSuccessAt()?->utc()->format('Y-m-d H:i:s'));
    }

    public function test_aviso_de_atraso_guarda_o_instante_para_nao_repetir(): void
    {
        $this->assertNull(BackupStatus::staleAlertedAt());

        $this->travelTo('2026-11-13 10:00:00');
        BackupStatus::markStaleAlerted();

        $this->assertSame('2026-11-13 10:00:00', BackupStatus::staleAlertedAt()?->utc()->format('Y-m-d H:i:s'));
    }

    public function test_payload_so_tem_kind_state_e_level(): void
    {
        $user = User::factory()->create();

        $this->assertSame(['kind' => 'backup', 'level' => 'critico', 'state' => 'failed'], (new BackupFailed)->toArray($user));
        $this->assertSame(['kind' => 'backup', 'level' => 'critico', 'state' => 'stale'], (new BackupStale)->toArray($user));
    }

    public function test_bell_mostra_backup_falhou_e_atrasado(): void
    {
        $eu = User::factory()->create(['role' => UserRole::ADMIN]);
        $eu->notify(new BackupFailed);
        $eu->notify(new BackupStale);

        $this->actingAs($eu);

        Livewire::test(Bell::class)
            ->assertSee('Backup do banco falhou')
            ->assertSee('Backup do banco atrasado')
            ->assertSee('Veja Configuração > Backup do banco');
    }
}
