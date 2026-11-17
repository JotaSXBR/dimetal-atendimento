<?php

namespace Tests\Feature\Livewire;

use App\Enums\AuditAction;
use App\Enums\UpdateStatus;
use App\Enums\UserRole;
use App\Jobs\ApplyUpdateJob;
use App\Livewire\Settings\System;
use App\Models\AuditLog;
use App\Models\SystemUpdate;
use App\Models\User;
use App\Services\Backup\ManualBackupState;
use App\Services\Backup\Restore\RestoreProgress;
use App\Settings\UpdateSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Update\FakesUpdateWorld;
use Tests\TestCase;

class SystemPageTest extends TestCase
{
    use FakesUpdateWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.display_timezone' => 'America/Sao_Paulo']);
        $this->prepareUpdateSettings();
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::ADMIN]);
    }

    public function test_so_admin_entra(): void
    {
        $this->get('/configuracoes/sistema')->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['role' => UserRole::SUPERVISOR]))->get('/configuracoes/sistema')->assertForbidden();
        $this->actingAs($this->admin())->get('/configuracoes/sistema')->assertOk()->assertSee('Sistema');
    }

    public function test_mostra_a_versao_instalada_e_a_disponivel(): void
    {
        Livewire::actingAs($this->admin())->test(System::class)
            ->assertSee('0.1.0')
            ->assertSee('0.2.0')
            ->assertSee('Ver novidades')
            ->assertSee('Testar conexão')
            ->assertSee('Atualizar para 0.2.0');
    }

    public function test_versao_de_desenvolvimento_nao_tem_botoes(): void
    {
        config(['fluxie.version' => null, 'fluxie.build' => 'sha-abcdef0123']);

        Livewire::actingAs($this->admin())->test(System::class)
            ->assertSee('desenvolvimento')
            ->assertSee('abcdef0')
            ->assertDontSee('Testar conexão')
            ->assertDontSee('Atualizar para');
    }

    public function test_sem_uuid_do_coolify_nao_tem_botoes(): void
    {
        config(['fluxie.resource_uuid' => null]);

        Livewire::actingAs($this->admin())->test(System::class)
            ->assertSee('não é um Service do Coolify')
            ->assertDontSee('Testar conexão')
            ->assertDontSee('Atualizar para');
    }

    public function test_sem_versao_maior_nao_tem_botao_atualizar(): void
    {
        config(['fluxie.version' => '0.2.0']);

        Livewire::actingAs($this->admin())->test(System::class)->assertDontSee('Atualizar para');
    }

    public function test_token_nunca_aparece_no_html_nem_no_snapshot(): void
    {
        $component = Livewire::actingAs($this->admin())->test(System::class)
            ->set('panelUrl', 'https://outro.example')
            ->set('tokenInput', '9|token-novo-secreto')
            ->call('saveConnection')
            ->assertSee('Token salvo em')
            ->assertSee('Trocar');

        $this->assertSame('9|token-novo-secreto', UpdateSettings::token());
        $this->assertSame('https://outro.example', UpdateSettings::panelUrl());
        $this->assertStringNotContainsString('token-novo-secreto', $component->html());
        $this->assertStringNotContainsString('token-novo-secreto', json_encode($component->snapshot));
        $this->assertStringNotContainsString('segredo-do-token', $component->html());
        $this->assertStringNotContainsString('segredo-do-token', json_encode($component->snapshot));
    }

    public function test_remover_token(): void
    {
        Livewire::actingAs($this->admin())->test(System::class)->call('removeToken');

        $this->assertNull(UpdateSettings::token());
    }

    public function test_liga_desliga_a_consulta(): void
    {
        Livewire::actingAs($this->admin())->test(System::class)->call('toggleCheck');

        $this->assertFalse(UpdateSettings::checkEnabled());
    }

    public function test_testar_conexao_tudo_certo(): void
    {
        $this->fakeWorld();

        Livewire::actingAs($this->admin())->test(System::class)->call('testConnection')->assertSee('Conexão com o Coolify funcionando');
    }

    public function test_testar_conexao_mostra_a_falha_em_portugues(): void
    {
        $this->world['service_get'] = [200, ['uuid' => 'svc123']];
        $this->fakeWorld();

        Livewire::actingAs($this->admin())->test(System::class)->call('testConnection')->assertSee('read:sensitive');
    }

    public function test_atualizar_sem_a_palavra_nao_dispara(): void
    {
        Queue::fake();

        Livewire::actingAs($this->admin())->test(System::class)->set('confirm', 'atualizar')->call('update');

        $this->assertSame(0, SystemUpdate::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_atualizar_com_a_palavra_cria_audita_e_despacha(): void
    {
        Queue::fake();
        $admin = $this->admin();

        Livewire::actingAs($admin)->test(System::class)->set('confirm', 'ATUALIZAR')->call('update');

        $update = SystemUpdate::query()->sole();
        $this->assertSame(UpdateStatus::Conferindo, $update->status);
        $this->assertSame(['0.1.0', '0.2.0', $admin->id], [$update->from_version, $update->to_version, $update->requested_by]);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::UpdateRequested->value)->where('subject', '0.2.0')->count());
        Queue::assertPushedOn('history', ApplyUpdateJob::class, fn (ApplyUpdateJob $job): bool => $job->updateId === $update->id);
    }

    public function test_ja_ha_atualizacao_em_andamento(): void
    {
        Queue::fake();
        SystemUpdate::start(null, '0.1.0', '0.2.0');

        Livewire::actingAs($this->admin())->test(System::class)->set('confirm', 'ATUALIZAR')->call('update');

        $this->assertSame(1, SystemUpdate::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_recusa_com_restauracao_ou_backup_manual_rodando(): void
    {
        Queue::fake();
        app(RestoreProgress::class)->begin('Restaurando o banco', ['inspect']);

        Livewire::actingAs($this->admin())->test(System::class)->set('confirm', 'ATUALIZAR')->call('update')->assertSee('restauração em andamento');
        $this->assertSame(0, SystemUpdate::query()->count());

        app(RestoreProgress::class)->fail('x');
        (new ManualBackupState)->markStarted();

        Livewire::actingAs($this->admin())->test(System::class)->set('confirm', 'ATUALIZAR')->call('update')->assertSee('backup em andamento');
        $this->assertSame(0, SystemUpdate::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_abrir_a_confirmacao_avisa_de_compose_editado(): void
    {
        $this->world['installed_compose'] = [200, 'services:
  app:
    image: original
'];
        $this->fakeWorld();

        Livewire::actingAs($this->admin())->test(System::class)->call('askUpdate')->assertSet('composeEdited', true)->assertSee('editado');
    }

    public function test_atualizacao_aberta_mostra_os_passos_e_atualiza_sozinha(): void
    {
        SystemUpdate::start(null, '0.1.0', '0.2.0')?->moveTo(UpdateStatus::Backup);

        Livewire::actingAs($this->admin())->test(System::class)
            ->assertSee('Conferindo')
            ->assertSee('Fazendo backup')
            ->assertSee('Aplicando')
            ->assertSee('Reiniciando')
            ->assertSeeHtml('wire:poll.3s')
            ->assertDontSee('Atualizar para');
    }

    public function test_aberta_ha_mais_de_30_minutos_diz_sem_confirmacao(): void
    {
        $update = SystemUpdate::start(null, '0.1.0', '0.2.0');
        $update?->forceFill(['started_at' => now()->subMinutes(31)])->save();

        Livewire::actingAs($this->admin())->test(System::class)->assertSee('Sem confirmação há mais de 30 minutos')->assertSee('docs/deploy.md');
    }

    public function test_ultima_fechada_mostra_resultado_e_mensagem(): void
    {
        SystemUpdate::query()->create(['status' => UpdateStatus::Revertida, 'from_version' => '0.1.0', 'to_version' => '0.2.0', 'message' => 'O sistema não subiu.', 'started_at' => now()->subHour(), 'step_at' => now(), 'finished_at' => now()]);

        Livewire::actingAs($this->admin())->test(System::class)->assertSee('Revertida')->assertSee('O sistema não subiu.')->assertDontSeeHtml('wire:poll');
    }

    public function test_consultar_agora_usa_o_github(): void
    {
        Http::fake(['api.github.com/*' => Http::response(['tag_name' => 'v0.3.0', 'html_url' => 'https://github.com/x', 'published_at' => '2026-11-18T10:00:00Z', 'assets' => [['name' => 'docker-compose.yml', 'browser_download_url' => 'https://github.com/c']]])]);

        Livewire::actingAs($this->admin())->test(System::class)->call('checkNow')->assertSee('0.3.0');
    }
}
