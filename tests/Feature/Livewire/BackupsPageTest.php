<?php

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Settings\Backups;
use App\Models\User;
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

        Livewire::actingAs($this->admin())->test(Backups::class)->call('runNow')->assertSee('Backup iniciado');

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
}
