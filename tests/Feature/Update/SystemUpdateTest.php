<?php

namespace Tests\Feature\Update;

use App\Enums\AuditAction;
use App\Enums\UpdateStatus;
use App\Enums\UserRole;
use App\Livewire\Settings\Backups;
use App\Models\AuditLog;
use App\Models\SystemUpdate;
use App\Models\User;
use App\Services\Backup\Restore\AdminDatabase;
use App\Services\Backup\Restore\StoredDatabase;
use App\Services\Backup\RunBackup;
use App\Support\AuditLogText;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class SystemUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_so_uma_atualizacao_aberta_por_vez(): void
    {
        $first = SystemUpdate::start(null, '0.1.0', '0.2.0');

        $this->assertNotNull($first);
        $this->assertSame(UpdateStatus::Conferindo, $first->status);
        $this->assertNull(SystemUpdate::start(null, '0.1.0', '0.2.0'));
        $this->assertTrue(SystemUpdate::open()?->is($first));

        $first->moveTo(UpdateStatus::Concluida);

        $this->assertNull(SystemUpdate::open());
        $this->assertNotNull(SystemUpdate::start(null, '0.2.0', '0.3.0'));
    }

    public function test_mover_grava_o_passo_e_as_fechadas_gravam_o_fim(): void
    {
        $update = SystemUpdate::start(null, '0.1.0', '0.2.0');
        $this->assertNotNull($update);

        $update->moveTo(UpdateStatus::Backup);
        $update->refresh();
        $this->assertNotNull($update->step_at);
        $this->assertNull($update->finished_at);

        $update->moveTo(UpdateStatus::Falhou, 'Deu ruim.');
        $update->refresh();
        $this->assertNotNull($update->finished_at);
        $this->assertSame('Deu ruim.', $update->message);
    }

    public function test_estados_abertos_e_rotulos(): void
    {
        $open = array_filter(UpdateStatus::cases(), fn (UpdateStatus $s) => $s->isOpen());

        $this->assertSame([UpdateStatus::Conferindo, UpdateStatus::Backup, UpdateStatus::Aplicando, UpdateStatus::Reiniciando], array_values($open));

        foreach (UpdateStatus::cases() as $status) {
            $this->assertNotSame('', $status->label());
        }
    }

    public function test_compose_guardado_nao_fica_em_texto_puro_no_banco(): void
    {
        $update = SystemUpdate::start(null, '0.1.0', '0.2.0');
        $this->assertNotNull($update);
        $update->update(['previous_compose' => 'services: segredo-do-compose']);

        $raw = DB::table('system_updates')->where('id', $update->id)->value('previous_compose');

        $this->assertIsString($raw);
        $this->assertStringNotContainsString('segredo-do-compose', $raw);
        $this->assertSame('services: segredo-do-compose', $update->fresh()?->previous_compose);
    }

    public function test_backup_manual_e_restauracao_sao_recusados_com_atualizacao_aberta(): void
    {
        Queue::fake();
        $this->mock(AdminDatabase::class)->shouldReceive('stored')->andReturn([new StoredDatabase('fluxie_antes_202611131000', CarbonImmutable::now(), 1_000_000)]);
        SystemUpdate::start(null, '0.1.0', '0.2.0');
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $message = 'Há uma atualização do sistema em andamento; espere ela terminar.';

        $page = Livewire::actingAs($admin)->test(Backups::class);
        $page->call('runNow')->assertSet('problem', $message);
        Queue::assertNothingPushed();

        $page->call('chooseStored', 'fluxie_antes_202611131000')->set('confirm', 'RESTAURAR')->call('switchBack')->assertSet('problem', $message);
        Queue::assertNothingPushed();
    }

    public function test_diario_pula_em_aplicando_e_reiniciando(): void
    {
        $this->mock(RunBackup::class)->shouldNotReceive('__invoke');
        $update = SystemUpdate::start(null, '0.1.0', '0.2.0');
        $this->assertNotNull($update);

        foreach ([UpdateStatus::Aplicando, UpdateStatus::Reiniciando] as $status) {
            $update->moveTo($status);
            $this->artisan('app:backup-database')->expectsOutputToContain('atualização')->assertSuccessful();
        }
    }

    public function test_frases_da_auditoria(): void
    {
        $text = fn (AuditAction $a, array $detail = []): string => AuditLogText::sentence(new AuditLog(['action' => $a->value, 'actor_label' => 'Ana', 'subject' => '0.2.0', 'detail' => $detail]));

        $this->assertSame('Ana pediu a atualização para 0.2.0', $text(AuditAction::UpdateRequested));
        $this->assertSame('Atualização para 0.2.0 concluída, pedida por Ana.', $text(AuditAction::UpdateSucceeded));
        $this->assertSame('Atualização para 0.2.0 desfeita, pedida por Ana. Voltou.', $text(AuditAction::UpdateReverted, ['message' => 'Voltou.']));
        $this->assertSame('Atualização para 0.2.0 falhou, pedida por Ana. Erro.', $text(AuditAction::UpdateFailed, ['message' => 'Erro.']));
        $this->assertSame('Configurações', AuditAction::UpdateFailed->group());
    }
}
