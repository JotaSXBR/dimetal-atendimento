<?php

namespace Tests\Feature\Console;

use App\Services\Backup\BackupFile;
use App\Services\Backup\BackupKind;
use App\Services\Backup\DumpFailed;
use App\Services\Backup\ManualBackupState;
use App\Services\Backup\Restore\RestoreProgress;
use App\Services\Backup\RunBackup;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class BackupDatabaseCommandTest extends TestCase
{
    public function test_diario_por_padrao_e_mostra_nome_e_tamanho(): void
    {
        $file = new BackupFile('dimetal-2026-11-12-0215-diario.dump', BackupKind::Diario, CarbonImmutable::now(), 2_500_000);
        $this->mock(RunBackup::class)->shouldReceive('__invoke')->once()->with(BackupKind::Diario)->andReturn($file);

        $this->artisan('app:backup-database')
            ->expectsOutputToContain('dimetal-2026-11-12-0215-diario.dump (2,5 MB)')
            ->assertSuccessful();
    }

    public function test_com_manual_roda_o_tipo_manual(): void
    {
        $file = new BackupFile('dimetal-2026-11-12-1000-manual.dump', BackupKind::Manual, CarbonImmutable::now(), 1_000_000);
        $this->mock(RunBackup::class)->shouldReceive('__invoke')->once()->with(BackupKind::Manual)->andReturn($file);

        $this->artisan('app:backup-database --manual')->assertSuccessful();
    }

    public function test_ja_em_andamento_sai_sem_erro(): void
    {
        $this->mock(RunBackup::class)->shouldReceive('__invoke')->once()->andReturn(null);

        $this->artisan('app:backup-database')->expectsOutputToContain('em andamento')->assertSuccessful();
    }

    public function test_falha_sai_com_codigo_1_e_a_mensagem(): void
    {
        $this->mock(RunBackup::class)->shouldReceive('__invoke')->once()->andThrow(new DumpFailed('pg_dump falhou (código 1): conexão recusada'));

        $this->artisan('app:backup-database')->expectsOutputToContain('conexão recusada')->assertFailed();
    }

    public function test_manual_marca_concluido_com_o_nome(): void
    {
        $file = new BackupFile('dimetal-2026-11-12-1000-manual.dump', BackupKind::Manual, CarbonImmutable::now(), 1_000_000);
        $this->mock(RunBackup::class)->shouldReceive('__invoke')->once()->andReturn($file);
        (new ManualBackupState)->markStarted();

        $this->artisan('app:backup-database --manual')->assertSuccessful();

        $this->assertSame(['status' => 'done', 'file' => 'dimetal-2026-11-12-1000-manual.dump', 'error' => null], (new ManualBackupState)->get());
    }

    public function test_manual_que_falha_marca_a_mensagem(): void
    {
        $this->mock(RunBackup::class)->shouldReceive('__invoke')->once()->andThrow(new DumpFailed('pg_dump falhou (código 1).'));
        (new ManualBackupState)->markStarted();

        $this->artisan('app:backup-database --manual')->assertFailed();

        $this->assertSame(['status' => 'failed', 'file' => null, 'error' => 'pg_dump falhou (código 1).'], (new ManualBackupState)->get());
    }

    public function test_manual_com_lock_ocupado_marca_que_ja_havia_um_em_andamento(): void
    {
        $this->mock(RunBackup::class)->shouldReceive('__invoke')->once()->andReturn(null);
        (new ManualBackupState)->markStarted();

        $this->artisan('app:backup-database --manual')->assertSuccessful();

        $this->assertSame('failed', (new ManualBackupState)->get()['status']);
        $this->assertStringContainsString('em andamento', (string) (new ManualBackupState)->get()['error']);
    }

    public function test_diario_nao_mexe_no_estado_manual(): void
    {
        $file = new BackupFile('dimetal-2026-11-12-0215-diario.dump', BackupKind::Diario, CarbonImmutable::now(), 1_000_000);
        $this->mock(RunBackup::class)->shouldReceive('__invoke')->once()->andReturn($file);
        $state = new ManualBackupState;
        $state->markStarted();

        $this->artisan('app:backup-database')->assertSuccessful();

        $this->assertSame('running', $state->get()['status']);
    }

    public function test_manual_durante_uma_restauracao_nao_faz_backup(): void
    {
        app(RestoreProgress::class)->begin('Restaurando o banco', ['inspect']);
        $this->mock(RunBackup::class)->shouldNotReceive('__invoke');
        (new ManualBackupState)->markStarted();

        $this->artisan('app:backup-database --manual')->assertSuccessful();

        $this->assertSame('failed', (new ManualBackupState)->get()['status']);
        $this->assertStringContainsString('restauração em andamento', (string) (new ManualBackupState)->get()['error']);
    }

    public function test_diario_durante_uma_restauracao_espera_o_proximo_dia(): void
    {
        app(RestoreProgress::class)->begin('Restaurando o banco', ['inspect']);
        $this->mock(RunBackup::class)->shouldNotReceive('__invoke');

        $this->artisan('app:backup-database')->expectsOutputToContain('restauração em andamento')->assertSuccessful();
    }
}
