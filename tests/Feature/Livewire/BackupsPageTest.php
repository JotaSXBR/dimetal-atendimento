<?php

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Settings\Backups;
use App\Models\User;
use App\Services\Backup\ManualBackupState;
use App\Services\Backup\Restore\RestoreProgress;
use App\Settings\BackupStatus;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Console\QueuedCommand;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class BackupsPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('backups');
        config(['app.display_timezone' => 'America/Sao_Paulo']);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::ADMIN]);
    }

    public function test_so_admin_entra(): void
    {
        $this->get('/configuracoes/backups')->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['role' => UserRole::SUPERVISOR]))->get('/configuracoes/backups')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::ATENDENTE]))->get('/configuracoes/backups')->assertForbidden();
        $this->actingAs($this->admin())->get('/configuracoes/backups')->assertOk();
    }

    public function test_lista_mostra_nome_tipo_data_tamanho_e_total(): void
    {
        Storage::disk('backups')->put('dimetal-2026-11-12-0215-diario.dump', str_repeat('a', 1_500_000));
        Storage::disk('backups')->put('dimetal-2026-11-12-1000-manual.dump', str_repeat('b', 500_000));

        Livewire::actingAs($this->admin())->test(Backups::class)
            ->assertSee('dimetal-2026-11-12-0215-diario.dump')
            ->assertSee('dimetal-2026-11-12-1000-manual.dump')
            ->assertSee('Diário')
            ->assertSee('Manual')
            ->assertSee('12/11/2026 02:15')
            ->assertSee('1,5 MB')
            ->assertSee('2,0 MB em 2 arquivos')
            ->assertSee(route('configuracoes.backups.baixar', 'dimetal-2026-11-12-0215-diario.dump'), false);
    }

    public function test_estado_vazio_sem_backups(): void
    {
        Livewire::actingAs($this->admin())->test(Backups::class)->assertSee('Nenhum backup ainda');
    }

    public function test_mostra_ultimo_sucesso_e_ultimo_erro_sem_senha(): void
    {
        $this->travelTo('2026-11-12 05:15:00');
        BackupStatus::recordSuccess();
        BackupStatus::recordFailure('pg_dump falhou (código 1): conexão recusada');

        Livewire::actingAs($this->admin())->test(Backups::class)
            ->assertSee('12/11/2026 02:15')
            ->assertSee('pg_dump falhou (código 1): conexão recusada');
    }

    public function test_sem_backup_bom_mostra_nunca(): void
    {
        Livewire::actingAs($this->admin())->test(Backups::class)->assertSee('Nunca');
    }

    public function test_fazer_agora_enfileira_o_comando_manual_sem_rodar_no_request(): void
    {
        Queue::fake();

        Livewire::actingAs($this->admin())->test(Backups::class)->call('runNow')->assertSee('Backup em andamento');

        // Fila `history`: o worker dela tem timeout de 900 s; na `default` (120 s) um dump longo mataria o worker inteiro.
        Queue::assertPushed(QueuedCommand::class, fn (QueuedCommand $job): bool => $job->queue === 'history');
        $this->assertSame([], Storage::disk('backups')->files());
    }

    public function test_bucket_ilegivel_mostra_aviso_em_vez_de_erro_500(): void
    {
        $disk = Mockery::mock(Filesystem::class);
        $disk->shouldReceive('files')->andThrow(new RuntimeException('credencial recusada'));
        Storage::set('backups', $disk);

        Livewire::actingAs($this->admin())->test(Backups::class)
            ->assertOk()
            ->assertSee('Não foi possível ler o bucket de backups')
            ->assertDontSee('credencial recusada');
    }

    public function test_quem_nao_e_admin_nao_executa(): void
    {
        Queue::fake();
        $supervisor = User::factory()->create(['role' => UserRole::SUPERVISOR]);

        Livewire::actingAs($supervisor)->test(Backups::class)->assertForbidden();

        Queue::assertNothingPushed();
    }

    public function test_fazer_agora_marca_em_andamento_e_a_tela_faz_polling(): void
    {
        Queue::fake();

        Livewire::actingAs($this->admin())->test(Backups::class)->call('runNow')
            ->assertSee('Backup em andamento')
            ->assertSeeHtml('wire:poll.3s');

        $this->assertTrue((new ManualBackupState)->isRunning());
    }

    public function test_clicar_de_novo_com_um_em_andamento_nao_enfileira_outro(): void
    {
        Queue::fake();
        (new ManualBackupState)->markStarted();

        Livewire::actingAs($this->admin())->test(Backups::class)->call('runNow')->assertSee('Já há um backup em andamento');

        Queue::assertNothingPushed();
    }

    public function test_tela_sem_backup_em_andamento_nao_faz_polling(): void
    {
        Livewire::actingAs($this->admin())->test(Backups::class)->assertDontSeeHtml('wire:poll');
    }

    public function test_mostra_o_backup_concluido_e_para_de_consultar(): void
    {
        (new ManualBackupState)->markFinished('dimetal-2026-11-12-1000-manual.dump', null);

        Livewire::actingAs($this->admin())->test(Backups::class)
            ->assertSee('Backup concluído: dimetal-2026-11-12-1000-manual.dump')
            ->assertDontSeeHtml('wire:poll');
    }

    public function test_mostra_a_falha_do_backup_manual(): void
    {
        (new ManualBackupState)->markFinished(null, 'pg_dump falhou (código 1).');

        Livewire::actingAs($this->admin())->test(Backups::class)->assertSee('O backup manual falhou')->assertSee('pg_dump falhou (código 1).');
    }

    public function test_fazer_agora_e_recusado_durante_uma_restauracao(): void
    {
        Queue::fake();
        app(RestoreProgress::class)->begin('Restaurando o banco', ['inspect']);

        Livewire::actingAs($this->admin())->test(Backups::class)->call('runNow')->assertSee('restauração em andamento');

        Queue::assertNothingPushed();
        $this->assertFalse((new ManualBackupState)->isRunning());
    }
}
