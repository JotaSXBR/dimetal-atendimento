<?php

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Jobs\RestoreDatabaseJob;
use App\Livewire\Settings\Backups;
use App\Models\User;
use App\Services\Backup\BackupCatalog;
use App\Services\Backup\Restore\AdminDatabase;
use App\Services\Backup\Restore\DumpInfo;
use App\Services\Backup\Restore\DumpInspector;
use App\Services\Backup\Restore\InvalidDump;
use App\Services\Backup\Restore\RestoreProgress;
use App\Services\Backup\Restore\StoredDatabase;
use App\Settings\BackupStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class BackupsRestoreTest extends TestCase
{
    use RefreshDatabase;

    private const NAME = 'dimetal-2026-11-12-0215-diario.dump';

    private MockInterface $admin;

    private MockInterface $inspector;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backups');
        Storage::fake('local');
        Queue::fake();
        config(['app.display_timezone' => 'America/Sao_Paulo', 'app.maintenance.driver' => 'cache']);
        Storage::disk('backups')->put(self::NAME, 'conteudo');

        $this->admin = $this->mock(AdminDatabase::class);
        $this->admin->shouldReceive('canCreate')->andReturn(true)->byDefault();
        $this->admin->shouldReceive('stored')->andReturn([])->byDefault();

        $this->inspector = $this->mock(DumpInspector::class);
        $this->inspector->shouldReceive('inspect')->andReturnUsing(
            fn (string $path, string $name) => new DumpInfo($name, CarbonImmutable::parse('2026-11-12 05:15:00', 'UTC'), 'dimetal', '18.6', '18.5', 'zstd', 269, 1_500_000),
        )->byDefault();

        $this->user = User::factory()->create(['role' => UserRole::ADMIN, 'name' => 'Jessé', 'email' => 'jotasx@example.com']);
    }

    private function page(): Testable
    {
        return Livewire::actingAs($this->user)->test(Backups::class);
    }

    private function stored(string $name = 'dimetal_antes_202611131000'): StoredDatabase
    {
        return new StoredDatabase($name, CarbonImmutable::parse('2026-11-13 10:00:00', 'America/Sao_Paulo'), 2_500_000);
    }

    public function test_restaurar_abre_a_conferencia_com_os_dados_do_arquivo(): void
    {
        $this->page()->call('chooseBackup', self::NAME)
            ->assertSet('source', 'bucket:'.self::NAME)
            ->assertSee(self::NAME)
            ->assertSee('12/11/2026 02:15')
            ->assertSee('dimetal')
            ->assertSee('18.6')
            ->assertSee('zstd')
            ->assertSee('1,5 MB')
            ->assertSee('Digite RESTAURAR');
    }

    public function test_arquivo_estranho_mostra_o_erro_e_nao_abre_a_conferencia(): void
    {
        $this->inspector->shouldReceive('inspect')->andThrow(new InvalidDump('Este arquivo não é um backup deste sistema.'));

        $this->page()->call('chooseBackup', self::NAME)
            ->assertSet('source', null)
            ->assertSee('Este arquivo não é um backup deste sistema.')
            ->assertDontSee('Digite RESTAURAR');
    }

    public function test_backup_que_nao_esta_na_lista_e_recusado(): void
    {
        $this->page()->call('chooseBackup', '../.env')->assertSet('source', null)->assertSee('não existe mais');
    }

    public function test_sem_a_palavra_certa_nao_despacha(): void
    {
        $this->page()->call('chooseBackup', self::NAME)->set('confirm', 'restaurar')->call('restore')->assertSee('Digite RESTAURAR para confirmar');

        Queue::assertNothingPushed();
    }

    public function test_com_a_palavra_despacha_o_job_na_fila_history_e_vai_para_a_pagina_de_andamento(): void
    {
        $this->page()->call('chooseBackup', self::NAME)->set('confirm', 'RESTAURAR')->call('restore')
            ->assertRedirect(route('restauracao'));

        Queue::assertPushed(RestoreDatabaseJob::class, fn (RestoreDatabaseJob $job): bool => $job->queue === 'history'
            && $job->request->mode === 'restore'
            && $job->request->source === 'bucket:'.self::NAME
            && $job->request->actor === 'Jessé <jotasx@example.com>');
        $this->assertTrue(app(RestoreProgress::class)->isRunning());
    }

    public function test_recusa_com_restauracao_em_andamento(): void
    {
        $this->page()->call('chooseBackup', self::NAME)->set('confirm', 'RESTAURAR');
        app(RestoreProgress::class)->begin('Restaurando o banco', ['inspect']);

        $this->page()->call('chooseBackup', self::NAME)->set('confirm', 'RESTAURAR')->call('restore')->assertSee('Já há uma restauração em andamento');

        Queue::assertNothingPushed();
    }

    public function test_recusa_sem_manutencao_compartilhada_e_diz_o_que_falta(): void
    {
        config(['app.maintenance.driver' => 'file']);

        $this->page()->call('chooseBackup', self::NAME)->set('confirm', 'RESTAURAR')->call('restore')->assertSee('APP_MAINTENANCE_DRIVER=cache');

        Queue::assertNothingPushed();
    }

    public function test_recusa_sem_permissao_de_criar_banco(): void
    {
        $this->admin->shouldReceive('canCreate')->andReturn(false);

        $this->page()->call('chooseBackup', self::NAME)->set('confirm', 'RESTAURAR')->call('restore')->assertSee('não pode criar bancos');

        Queue::assertNothingPushed();
    }

    public function test_so_admin(): void
    {
        $supervisor = User::factory()->create(['role' => UserRole::SUPERVISOR]);

        Livewire::actingAs($supervisor)->test(Backups::class)->assertForbidden();
    }

    public function test_envio_de_arquivo_vai_para_o_bucket_em_restauracao_e_nao_aparece_na_lista(): void
    {
        $page = $this->page()->set('upload', UploadedFile::fake()->create('meu-backup.dump', 10))
            ->assertSee('Digite RESTAURAR');

        $files = Storage::disk('backups')->allFiles('restauracao');
        $this->assertCount(1, $files);
        $this->assertStringStartsWith('upload:', $page->get('source'));
        $this->assertSame([self::NAME], array_map(fn ($file) => $file->name, (new BackupCatalog)->all()));
    }

    public function test_envio_recusa_arquivo_sem_extensao_dump_e_acima_de_100mb(): void
    {
        $this->page()->set('upload', UploadedFile::fake()->create('notas.txt', 10))->assertHasErrors('upload')->assertSet('source', null);
        $this->page()->set('upload', UploadedFile::fake()->create('grande.dump', 102_401))->assertHasErrors('upload')->assertSet('source', null);

        $this->assertSame([], Storage::disk('backups')->allFiles('restauracao'));
    }

    public function test_cancelar_apaga_o_arquivo_enviado(): void
    {
        $page = $this->page()->set('upload', UploadedFile::fake()->create('meu-backup.dump', 10));
        $this->assertCount(1, Storage::disk('backups')->allFiles('restauracao'));

        $page->call('cancelRestore')->assertSet('source', null)->assertDontSee('Digite RESTAURAR');

        $this->assertSame([], Storage::disk('backups')->allFiles('restauracao'));
    }

    public function test_lista_de_bancos_guardados_com_data_e_tamanho(): void
    {
        $this->admin->shouldReceive('stored')->andReturn([$this->stored()]);

        $this->page()->assertSee('dimetal_antes_202611131000')->assertSee('13/11/2026 10:00')->assertSee('2,5 MB')->assertSee('Voltar para este');
    }

    public function test_nome_de_banco_guardado_forjado_e_recusado(): void
    {
        $this->admin->shouldReceive('stored')->andReturn([$this->stored()]);

        $this->page()->call('chooseStored', 'postgres')->assertSet('target', null)->assertSee('não existe mais')
            ->set('confirm', 'RESTAURAR')->call('switchBack');

        Queue::assertNothingPushed();
    }

    public function test_voltar_para_este_despacha_o_job_switch(): void
    {
        $this->admin->shouldReceive('stored')->andReturn([$this->stored()]);

        $this->page()->call('chooseStored', 'dimetal_antes_202611131000')->assertSee('Digite RESTAURAR')
            ->set('confirm', 'RESTAURAR')->call('switchBack')->assertRedirect(route('restauracao'));

        Queue::assertPushed(RestoreDatabaseJob::class, fn (RestoreDatabaseJob $job): bool => $job->request->mode === 'switch'
            && $job->request->source === 'dimetal_antes_202611131000');
    }

    public function test_ultima_restauracao_aparece(): void
    {
        BackupStatus::recordRestore('dimetal-2026-11-12-0215-diario.dump', 'Jessé <jotasx@example.com>');

        $this->page()->assertSee('Última restauração')->assertSee('Jessé');
    }
}
