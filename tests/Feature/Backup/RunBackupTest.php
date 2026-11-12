<?php

namespace Tests\Feature\Backup;

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\BackupFailed;
use App\Services\Backup\BackupCatalog;
use App\Services\Backup\BackupKind;
use App\Services\Backup\DumpFailed;
use App\Services\Backup\RunBackup;
use App\Settings\BackupStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class RunBackupTest extends TestCase
{
    use FakesPgDump, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backups');
        Notification::fake();
        config(['app.display_timezone' => 'America/Sao_Paulo']);
    }

    private function run_(BackupKind $kind = BackupKind::Diario): mixed
    {
        return app(RunBackup::class)($kind);
    }

    /** @return list<string> */
    private function names(): array
    {
        return array_map(fn ($file) => $file->name, (new BackupCatalog)->all());
    }

    public function test_diario_grava_com_o_nome_certo_e_registra_sucesso(): void
    {
        $this->travelTo('2026-11-11 05:15:00'); // quarta-feira
        $this->fakePgDump();
        BackupStatus::recordFailure('erro antigo');

        $file = $this->run_();

        $this->assertSame('dimetal-2026-11-11-0215-diario.dump', $file?->name);
        $this->assertSame('dump-bytes', Storage::disk('backups')->get('dimetal-2026-11-11-0215-diario.dump'));
        $this->assertSame(['dimetal-2026-11-11-0215-diario.dump'], $this->names());
        $this->assertNull(BackupStatus::lastError());
        $this->assertNotNull(BackupStatus::lastSuccessAt());
        $this->assertSame([], $this->leftoverDumps());
        Notification::assertNothingSent();
    }

    public function test_domingo_no_fuso_de_exibicao_cria_tambem_o_semanal(): void
    {
        $this->travelTo('2026-11-16 01:30:00'); // segunda em UTC, domingo 22:30 em São Paulo
        $this->fakePgDump();

        $this->run_();

        $this->assertEqualsCanonicalizing(
            ['dimetal-2026-11-15-2230-diario.dump', 'dimetal-2026-11-15-2230-semanal.dump'],
            $this->names(),
        );
        $this->assertSame('dump-bytes', Storage::disk('backups')->get('dimetal-2026-11-15-2230-semanal.dump'));
    }

    public function test_dia_comum_nao_cria_semanal(): void
    {
        $this->travelTo('2026-11-12 05:15:00'); // quinta-feira
        $this->fakePgDump();

        $this->run_();

        $this->assertSame(['dimetal-2026-11-12-0215-diario.dump'], $this->names());
    }

    public function test_manual_grava_com_sufixo_manual_e_nunca_cria_semanal(): void
    {
        $this->travelTo('2026-11-15 15:00:00'); // domingo
        $this->fakePgDump();

        $file = $this->run_(BackupKind::Manual);

        $this->assertSame('dimetal-2026-11-15-1200-manual.dump', $file?->name);
        $this->assertSame(['dimetal-2026-11-15-1200-manual.dump'], $this->names());
    }

    public function test_poda_depois_do_sucesso(): void
    {
        $this->travelTo('2026-11-12 05:15:00');
        $this->fakePgDump();
        $catalog = new BackupCatalog;

        foreach (range(1, 7) as $daysAgo) {
            Storage::disk('backups')->put($catalog->name(BackupKind::Diario, CarbonImmutable::now()->subDays($daysAgo)), 'x');
        }

        $this->run_();

        $this->assertCount(7, $this->names());
        $this->assertContains('dimetal-2026-11-12-0215-diario.dump', $this->names());
        $this->assertNotContains('dimetal-2026-11-05-0215-diario.dump', $this->names());
    }

    public function test_falha_nao_poda_nao_apaga_backup_bom_e_avisa_so_os_admins(): void
    {
        $this->travelTo('2026-11-12 05:15:00');
        $this->fakePgDump(dumpExit: 1, dumpStderr: 'pg_dump: erro: conexão recusada');
        $catalog = new BackupCatalog;
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $supervisor = User::factory()->create(['role' => UserRole::SUPERVISOR]);

        foreach (range(1, 8) as $daysAgo) {
            Storage::disk('backups')->put($catalog->name(BackupKind::Diario, CarbonImmutable::now()->subDays($daysAgo)), 'x');
        }

        try {
            $this->run_();
            $this->fail('devia lançar DumpFailed');
        } catch (DumpFailed) {
            // esperado
        }

        $this->assertCount(8, $this->names());
        $this->assertStringContainsString('conexão recusada', (string) BackupStatus::lastError());
        $this->assertNull(BackupStatus::lastSuccessAt());
        Notification::assertSentTo($admin, BackupFailed::class);
        Notification::assertNotSentTo($supervisor, BackupFailed::class);
        $this->assertSame([], $this->leftoverDumps());
    }

    public function test_falha_ao_registrar_a_falha_nao_esconde_o_erro_do_dump(): void
    {
        // Banco fora do ar: o estado e o aviso também não gravam, mas quem chama precisa ver o motivo do dump.
        $this->fakePgDump(dumpExit: 1, dumpStderr: 'pg_dump: erro: conexão recusada');
        Notification::shouldReceive('send')->andThrow(new RuntimeException('banco fora do ar'));

        try {
            $this->run_();
            $this->fail('devia lançar DumpFailed');
        } catch (DumpFailed $e) {
            $this->assertStringContainsString('conexão recusada', $e->getMessage());
        }
    }

    public function test_segunda_execucao_com_o_lock_ocupado_nao_faz_nada(): void
    {
        $this->fakePgDump();
        $lock = Cache::lock('backup-database', 60);
        $this->assertTrue($lock->get());

        $this->assertNull($this->run_());

        $this->assertSame([], $this->names());
        Process::assertNothingRan();
        $lock->release();
    }

    public function test_o_lock_e_liberado_ao_terminar(): void
    {
        $this->fakePgDump();

        $this->run_();

        $lock = Cache::lock('backup-database', 60);
        $this->assertTrue($lock->get());
        $lock->release();
    }
}
