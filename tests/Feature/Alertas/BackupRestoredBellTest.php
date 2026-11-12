<?php

namespace Tests\Feature\Alertas;

use App\Enums\UserRole;
use App\Livewire\Alerts\Bell;
use App\Models\User;
use App\Notifications\BackupRestored;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BackupRestoredBellTest extends TestCase
{
    use RefreshDatabase;

    public function test_payload_so_tem_kind_state_e_level(): void
    {
        $this->assertSame(['kind' => 'backup', 'level' => 'info', 'state' => 'restored'], (new BackupRestored)->toArray(User::factory()->make()));
    }

    public function test_bell_mostra_banco_restaurado(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $admin->notify(new BackupRestored);

        $this->actingAs($admin);

        Livewire::test(Bell::class)->assertSee('Banco restaurado')->assertSee('Configuração > Backup do banco');
    }
}
