<?php

namespace Tests\Feature\Update;

use App\Enums\AuditAction;
use App\Enums\UpdateStatus;
use App\Jobs\ApplyUpdateJob;
use App\Models\AuditLog;
use App\Models\SystemUpdate;
use App\Services\Backup\BackupFile;
use App\Services\Backup\BackupKind;
use App\Services\Backup\DumpFailed;
use App\Services\Backup\Restore\RestoreProgress;
use App\Services\Backup\RunBackup;
use App\Services\Update\ApplyUpdate;
use App\Settings\UpdateSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ApplyUpdateTest extends TestCase
{
    use FakesUpdateWorld;
    use RefreshDatabase;

    private SystemUpdate $update;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareUpdateSettings();
        $this->update = SystemUpdate::start(null, '0.1.0', '0.2.0') ?? throw new RuntimeException('trava');
    }

    private function backupFile(): BackupFile
    {
        return new BackupFile('dimetal-2026-11-17-1000-manual.dump', BackupKind::Manual, CarbonImmutable::now(), 5_000_000);
    }

    private function backupOk(): MockInterface
    {
        return $this->mock(RunBackup::class, fn (MockInterface $m) => $m->shouldReceive('__invoke')->once()->with(BackupKind::Manual)->andReturn($this->backupFile()));
    }

    private function noBackup(): void
    {
        $this->mock(RunBackup::class, fn (MockInterface $m) => $m->shouldNotReceive('__invoke'));
    }

    private function apply(): SystemUpdate
    {
        $this->fakeWorld();
        app(ApplyUpdate::class)($this->update);

        return $this->update->refresh();
    }

    /** @return list<Request> */
    private function composeWrites(): array
    {
        return array_values(array_filter($this->writes(), fn (Request $r): bool => $r->method() === 'PATCH' && str_ends_with($r->url(), '/svc123')));
    }

    /** @return list<array<string, mixed>> */
    private function envValues(): array
    {
        return array_map(fn (Request $r): array => $r->data(), array_values(array_filter($this->writes(), fn (Request $r): bool => str_ends_with($r->url(), '/envs'))));
    }

    private function assertNothingChangedBehaviourInCoolify(): void
    {
        $this->assertSame([], $this->composeWrites());
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/svc123/restart'));
        foreach ($this->envValues() as $env) {
            $this->assertSame('0.1.0', $env['value']); // só a gravação de prova do pré-voo: a versão que já roda
        }
    }

    private function audit(AuditAction $action): AuditLog
    {
        return AuditLog::query()->where('action', $action->value)->firstOrFail();
    }

    public function test_caminho_feliz_guarda_o_anterior_grava_o_novo_e_pede_o_reinicio_com_pull(): void
    {
        $this->backupOk();

        $update = $this->apply();

        $this->assertSame(UpdateStatus::Reiniciando, $update->status);
        $this->assertSame('dimetal-2026-11-17-1000-manual.dump', $update->backup_file);
        $this->assertSame(self::CURRENT_COMPOSE, $update->previous_compose);
        $this->assertSame('0.1.0', $update->previous_version);

        $compose = $this->composeWrites();
        $this->assertCount(1, $compose);
        $this->assertSame(base64_encode($this->releaseCompose()), $compose[0]->data()['docker_compose_raw']);
        $this->assertSame('0.2.0', $this->envs['FLUXIE_VERSION']);
        Http::assertSent(fn (Request $r): bool => $r->method() === 'POST' && $r->url() === self::PANEL.'/svc123/restart?latest=true');
    }

    public function test_o_backup_termina_antes_de_qualquer_troca_no_coolify(): void
    {
        $this->mock(RunBackup::class, fn (MockInterface $m) => $m->shouldReceive('__invoke')->once()->andReturnUsing(function (): BackupFile {
            $this->assertSame([], $this->composeWrites());
            Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/restart?latest'));

            return $this->backupFile();
        }));

        $this->assertSame(UpdateStatus::Reiniciando, $this->apply()->status);
    }

    /** @return array<string, array{string, array{int, mixed}, string}> */
    public static function falhasDoPrevoo(): array
    {
        return [
            'sem read:sensitive' => ['service_get', [200, ['uuid' => 'svc123']], 'read:sensitive'],
            'sem ler variáveis' => ['envs_get', [403, ['message' => 'Missing required permissions: read']], 'read'],
            'sem write' => ['envs_patch', [403, ['message' => 'Missing required permissions: write']], 'write'],
            'sem deploy' => ['probe', [403, ['message' => 'Missing required permissions: deploy']], 'deploy'],
            'imagem inexistente' => ['manifest', [404, '{"errors":[]}'], 'GHCR'],
            'compose da release inválido' => ['release_compose', [200, "services:\n  web: {}\n"], 'app'],
            'compose da release não baixa' => ['release_compose', [500, 'erro'], 'docker-compose.yml da Release'],
        ];
    }

    /** @param  array{int, mixed}  $stub */
    #[DataProvider('falhasDoPrevoo')]
    public function test_falha_do_prevoo_para_em_falhou_sem_backup_e_sem_trocar_nada(string $key, array $stub, string $mention): void
    {
        $this->noBackup();
        $this->world[$key] = $stub;

        $update = $this->apply();

        $this->assertSame(UpdateStatus::Falhou, $update->status);
        $this->assertStringContainsString($mention, (string) $update->message);
        $this->assertNothingChangedBehaviourInCoolify();
        $this->assertStringContainsString($mention, $this->audit(AuditAction::UpdateFailed)->detail['message']);
        $this->assertSame('0.2.0', $this->audit(AuditAction::UpdateFailed)->subject);
    }

    public function test_sem_deploy_nao_manda_nenhuma_escrita_ao_coolify(): void
    {
        $this->noBackup();
        $this->world['probe'] = [403, ['message' => 'Missing required permissions: deploy']];

        $this->assertSame(UpdateStatus::Falhou, $this->apply()->status);
        $this->assertNoCoolifyWrites();
    }

    public function test_sem_read_sensitive_nao_manda_nenhuma_escrita_ao_coolify(): void
    {
        $this->noBackup();
        $this->world['service_get'] = [200, ['uuid' => 'svc123']];

        $this->assertSame(UpdateStatus::Falhou, $this->apply()->status);
        $this->assertNoCoolifyWrites();
    }

    public function test_latest_de_outra_versao_falha_sem_chamar_nada(): void
    {
        $this->noBackup();
        $this->update->update(['to_version' => '0.3.0']);

        $update = $this->apply();

        $this->assertSame(UpdateStatus::Falhou, $update->status);
        $this->assertStringContainsString('Consulte as versões de novo', (string) $update->message);
        $this->assertNoCoolifyWrites();
    }

    public function test_restauracao_em_andamento_falha_sem_backup(): void
    {
        $this->noBackup();
        app(RestoreProgress::class)->begin('Restaurando', ['inspect']);

        $update = $this->apply();

        $this->assertSame(UpdateStatus::Falhou, $update->status);
        $this->assertNothingChangedBehaviourInCoolify();
    }

    public function test_backup_que_falha_deixa_falhou_e_o_coolify_sem_troca(): void
    {
        $this->mock(RunBackup::class, fn (MockInterface $m) => $m->shouldReceive('__invoke')->once()->andThrow(new DumpFailed('pg_dump saiu com erro.')));

        $update = $this->apply();

        $this->assertSame(UpdateStatus::Falhou, $update->status);
        $this->assertStringContainsString('pg_dump saiu com erro.', (string) $update->message);
        $this->assertNull($update->backup_file);
        $this->assertNothingChangedBehaviourInCoolify();
        $this->assertNotNull($this->audit(AuditAction::UpdateFailed));
    }

    public function test_backup_ja_em_andamento_deixa_falhou(): void
    {
        $this->mock(RunBackup::class, fn (MockInterface $m) => $m->shouldReceive('__invoke')->once()->andReturnNull());

        $update = $this->apply();

        $this->assertSame(UpdateStatus::Falhou, $update->status);
        $this->assertStringContainsString('Já há um backup em andamento', (string) $update->message);
        $this->assertNothingChangedBehaviourInCoolify();
    }

    public function test_se_a_atualizacao_foi_fechada_durante_o_backup_nada_e_aplicado(): void
    {
        $this->mock(RunBackup::class, fn (MockInterface $m) => $m->shouldReceive('__invoke')->once()->andReturnUsing(function (): BackupFile {
            SystemUpdate::query()->whereKey($this->update->id)->update(['status' => UpdateStatus::Falhou->value]);

            return $this->backupFile();
        }));

        $this->assertSame(UpdateStatus::Falhou, $this->apply()->status);
        $this->assertNothingChangedBehaviourInCoolify();
    }

    public function test_restart_falhando_regrava_compose_e_versao_guardados_e_fica_revertida(): void
    {
        $this->envs['FLUXIE_VERSION'] = '0.1.0';
        $this->backupOk();
        $this->world['restart'] = [422, ['message' => 'boom']];

        $update = $this->apply();

        $this->assertSame(UpdateStatus::Revertida, $update->status);
        $this->assertStringContainsString('HTTP 422', (string) $update->message);
        $compose = $this->composeWrites();
        $this->assertCount(2, $compose);
        $this->assertSame(base64_encode($this->releaseCompose()), $compose[0]->data()['docker_compose_raw']);
        $this->assertSame(base64_encode(self::CURRENT_COMPOSE), $compose[1]->data()['docker_compose_raw']);
        $this->assertSame('0.1.0', $this->envs['FLUXIE_VERSION']);
        $this->assertSame('0.2.0', $this->envValues()[1]['value']);
        $this->assertSame('0.1.0', $this->envValues()[2]['value']);
        $this->assertStringContainsString('HTTP 422', $this->audit(AuditAction::UpdateReverted)->detail['message']);
        $this->assertNull(AuditLog::query()->where('action', AuditAction::UpdateFailed->value)->first());
    }

    /** Recusa definitiva (4xx): o Coolify não reiniciou, então devolve na hora. */
    public function test_restart_recusado_com_403_devolve_na_hora(): void
    {
        $this->envs['FLUXIE_VERSION'] = '0.1.0';
        $this->backupOk();
        $this->world['restart'] = [403, ['message' => 'Missing required permissions: deploy']];

        $update = $this->apply();

        $this->assertSame(UpdateStatus::Revertida, $update->status);
        $this->assertSame('0.1.0', $this->envs['FLUXIE_VERSION']);
    }

    /** @return array<string, array{mixed}> */
    public static function restartSemRespostaClara(): array
    {
        return [
            'sem conexão ou timeout' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out')],
            'HTTP 502' => [[502, ['message' => 'Bad Gateway']]],
            'HTTP 503' => [[503, ['message' => 'Service Unavailable']]],
        ];
    }

    /** Sem resposta clara o reinício pode ter acontecido: fica em `reiniciando` e o vigia decide pela versão que sobe. */
    #[DataProvider('restartSemRespostaClara')]
    public function test_restart_sem_resposta_clara_fica_reiniciando_sem_regravar(mixed $stub): void
    {
        $this->envs['FLUXIE_VERSION'] = '0.1.0';
        $this->backupOk();
        $this->world['restart'] = $stub;

        $update = $this->apply();

        $this->assertSame(UpdateStatus::Reiniciando, $update->status);
        $this->assertStringContainsString('vigia', (string) $update->message);
        $this->assertCount(1, $this->composeWrites());
        $this->assertSame('0.2.0', $this->envs['FLUXIE_VERSION']);
        $this->assertNull(AuditLog::query()->whereIn('action', [AuditAction::UpdateReverted->value, AuditAction::UpdateFailed->value])->first());
    }

    public function test_instalacao_sem_a_variavel_ganha_a_versao_rodando_no_prevoo_e_a_volta_a_regrava(): void
    {
        $this->assertArrayNotHasKey('FLUXIE_VERSION', $this->envs);
        $this->backupOk();
        $this->world['restart'] = [422, ['message' => 'boom']];

        $update = $this->apply();

        $this->assertSame(UpdateStatus::Revertida, $update->status);
        $this->assertSame('0.1.0', $update->previous_version);
        $values = array_column($this->envValues(), 'value');
        $this->assertSame(['0.1.0', '0.1.0', '0.2.0', '0.1.0'], $values); // PATCH 404, POST do pré-voo, versão nova, devolução
        $this->assertNotContains('', $values);
        $this->assertSame('0.1.0', $this->envs['FLUXIE_VERSION']);
    }

    public function test_variavel_presente_mas_vazia_nunca_e_guardada_nem_devolvida_vazia(): void
    {
        $this->envs['FLUXIE_VERSION'] = '';
        $this->backupOk();
        $this->world['restart'] = [422, ['message' => 'boom']];

        $update = $this->apply();

        $this->assertSame(UpdateStatus::Revertida, $update->status);
        $this->assertSame('0.1.0', $update->previous_version);
        $values = array_column($this->envValues(), 'value');
        $this->assertSame(['0.1.0', '0.2.0', '0.1.0'], $values);
        $this->assertNotContains('', $values);
        $this->assertSame('0.1.0', $this->envs['FLUXIE_VERSION']);
    }

    public function test_falha_ao_gravar_a_versao_depois_do_compose_tambem_devolve(): void
    {
        $this->envs['FLUXIE_VERSION'] = '0.1.0';
        $this->backupOk();
        $this->world['envs_patch'] = fn (Request $r) => $r['value'] === '0.2.0' ? Http::response(['message' => 'x'], 500) : Http::response(['uuid' => 'e'], 200);

        $update = $this->apply();

        $this->assertSame(UpdateStatus::Revertida, $update->status);
        $compose = $this->composeWrites();
        $this->assertSame(base64_encode(self::CURRENT_COMPOSE), end($compose)->data()['docker_compose_raw']);
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/svc123/restart'));
    }

    public function test_erro_inesperado_depois_da_primeira_escrita_tambem_devolve_e_e_relancado(): void
    {
        $this->envs['FLUXIE_VERSION'] = '0.1.0';
        $this->backupOk();
        $this->world['envs_patch'] = function (Request $r) {
            $r['value'] === '0.2.0' && throw new RuntimeException('falha que não é do Coolify');

            return Http::response(['uuid' => 'e'], 200);
        };
        $this->fakeWorld();

        try {
            app(ApplyUpdate::class)($this->update);
            $this->fail('Esperava a exceção inesperada relançada.');
        } catch (RuntimeException $e) {
            $this->assertSame('falha que não é do Coolify', $e->getMessage());
        }

        $this->assertSame(UpdateStatus::Revertida, $this->update->refresh()->status);
        $compose = $this->composeWrites();
        $this->assertSame(base64_encode(self::CURRENT_COMPOSE), end($compose)->data()['docker_compose_raw']);
        $this->assertSame('0.1.0', $this->envs['FLUXIE_VERSION']);
        $this->assertNotNull($this->audit(AuditAction::UpdateReverted));
    }

    public function test_o_token_nunca_aparece_no_estado_na_auditoria_nem_no_log(): void
    {
        $token = 'tok-secreto-123';
        UpdateSettings::setToken($token);
        Log::spy();
        $this->envs['FLUXIE_VERSION'] = '0.1.0';
        $this->backupOk();
        $this->world['restart'] = [422, ['message' => "erro com {$token}"]];

        $this->assertSame(UpdateStatus::Revertida, $this->apply()->status);

        // Mesmo com o painel ecoando o token no corpo do erro, nada que o app grava ou registra o leva.
        $failed = SystemUpdate::start(null, '0.2.0', '0.3.0') ?? throw new RuntimeException('trava');
        (new ApplyUpdateJob($failed->id))->failed(new RuntimeException('erro de rede'));
        $stored = json_encode([SystemUpdate::query()->get()->toArray(), AuditLog::query()->get()->toArray()], JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($token, (string) $stored);
        Log::shouldNotHaveReceived('error', fn (string $message, array $context = []): bool => str_contains($message.json_encode($context), $token));
        Http::assertSent(fn (Request $r): bool => $r->hasHeader('Authorization', 'Bearer '.$token)); // o token foi usado de fato
    }

    public function test_se_a_devolucao_tambem_falha_fica_falhou_com_o_caminho_da_recuperacao(): void
    {
        $this->envs['FLUXIE_VERSION'] = '0.1.0';
        $this->backupOk();
        $this->world['restart'] = [422, ['message' => 'boom']];
        $patches = 0;
        $this->world['service_patch'] = function () use (&$patches) {
            return ++$patches === 1 ? Http::response(['message' => 'ok'], 200) : Http::response(['message' => 'x'], 502);
        };

        $update = $this->apply();

        $this->assertSame(UpdateStatus::Falhou, $update->status);
        $this->assertStringContainsString('docs/deploy.md', (string) $update->message);
        $this->assertNotNull($this->audit(AuditAction::UpdateFailed));
    }

    /** @return array<string, array{UpdateStatus}> */
    public static function estadosAlemDeConferindo(): array
    {
        return [
            'backup' => [UpdateStatus::Backup],
            'aplicando' => [UpdateStatus::Aplicando],
            'reiniciando' => [UpdateStatus::Reiniciando],
            'concluida' => [UpdateStatus::Concluida],
            'falhou' => [UpdateStatus::Falhou],
            'revertida' => [UpdateStatus::Revertida],
        ];
    }

    /** Simula o vigia fechando a atualização no meio do job: o Http do passo muda a linha no banco antes de responder. */
    private function closedByWatcherDuring(string $stub, UpdateStatus $to, mixed $response): void
    {
        $this->world[$stub] = function () use ($to, $response): mixed {
            // só depois do backup: o pré-voo também lê as variáveis
            SystemUpdate::query()->whereKey($this->update->id)->where('status', '<>', UpdateStatus::Conferindo->value)->update(['status' => $to->value]);

            return $response;
        };
    }

    public function test_vigia_fecha_entre_a_conferencia_e_o_backup_o_job_nao_faz_backup_nem_reabre(): void
    {
        $this->noBackup();
        $this->world['manifest'] = function (): array {
            SystemUpdate::query()->whereKey($this->update->id)->update(['status' => UpdateStatus::Falhou->value]);

            return [200, $this->fixture('ghcr-manifest-0.1.0.json')];
        };

        $update = $this->apply();

        $this->assertSame(UpdateStatus::Falhou, $update->status);
        $this->assertSame([], $this->composeWrites());
    }

    public function test_vigia_fecha_antes_de_aplicar_o_job_nao_grava_nem_reinicia(): void
    {
        $this->backupOk();
        $this->closedByWatcherDuring('envs_get', UpdateStatus::Falhou, [200, [['key' => 'FLUXIE_VERSION', 'value' => '0.1.0', 'is_preview' => false]]]);

        $update = $this->apply();

        $this->assertSame(UpdateStatus::Falhou, $update->status);
        $this->assertSame([], $this->composeWrites());
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/svc123/restart'));
    }

    public function test_vigia_devolve_durante_a_gravacao_o_job_nao_reabre_nem_reinicia(): void
    {
        $this->backupOk();
        $this->closedByWatcherDuring('service_patch', UpdateStatus::Revertida, [200, ['message' => 'ok']]);

        $update = $this->apply();

        $this->assertSame(UpdateStatus::Revertida, $update->status);
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/svc123/restart'));
        // o que o job gravou depois da devolução do vigia é desfeito: o último compose e a última versão são os anteriores
        $composes = $this->composeWrites();
        $this->assertSame(self::CURRENT_COMPOSE, base64_decode((string) end($composes)->data()['docker_compose_raw']));
        $envs = $this->envValues();
        $this->assertSame('0.1.0', end($envs)['value']);
    }

    public function test_desfazer_a_gravacao_que_falha_deixa_falhou_com_a_recuperacao_e_pede_novo_alerta(): void
    {
        $this->backupOk();
        $patches = 0;
        $this->world['service_patch'] = function () use (&$patches): array {
            if (++$patches === 1) { // o vigia devolve enquanto o job grava, e já avisou
                SystemUpdate::query()->whereKey($this->update->id)->update(['status' => UpdateStatus::Revertida->value, 'notified_at' => now()]);

                return [200, ['message' => 'ok']];
            }

            return [500, ['message' => 'quebrou']];
        };

        $update = $this->apply();

        $this->assertSame(UpdateStatus::Falhou, $update->status);
        $this->assertNull($update->notified_at);
        $this->assertStringContainsString('docs/deploy.md', (string) $update->message);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::UpdateFailed->value)->count());
    }

    #[DataProvider('estadosAlemDeConferindo')]
    public function test_job_repetido_em_estado_posterior_nao_chama_nada(UpdateStatus $status): void
    {
        $this->noBackup();
        $this->update->moveTo($status);

        $this->apply();

        Http::assertNothingSent();
        $this->assertSame($status, $this->update->status);
    }

    public function test_job_usa_a_fila_history_uma_tentativa_e_timeout_de_900(): void
    {
        $job = new ApplyUpdateJob($this->update->id);

        $this->assertSame('history', $job->queue);
        $this->assertSame(1, $job->tries);
        $this->assertSame(900, $job->timeout);
    }

    public function test_job_executa_a_atualizacao(): void
    {
        $this->backupOk();
        $this->fakeWorld();

        (new ApplyUpdateJob($this->update->id))->handle(app(ApplyUpdate::class));

        $this->assertSame(UpdateStatus::Reiniciando, $this->update->refresh()->status);
    }

    /** @return array<string, array{UpdateStatus, UpdateStatus}> */
    public static function falhaDoJob(): array
    {
        return [
            'conferindo' => [UpdateStatus::Conferindo, UpdateStatus::Falhou],
            'backup' => [UpdateStatus::Backup, UpdateStatus::Falhou],
            'aplicando fica para o vigia' => [UpdateStatus::Aplicando, UpdateStatus::Aplicando],
            'reiniciando fica para o vigia' => [UpdateStatus::Reiniciando, UpdateStatus::Reiniciando],
        ];
    }

    #[DataProvider('falhaDoJob')]
    public function test_failed_do_job_so_fecha_o_que_ainda_nao_mexeu_no_coolify(UpdateStatus $from, UpdateStatus $expected): void
    {
        $this->update->moveTo($from);

        (new ApplyUpdateJob($this->update->id))->failed(new RuntimeException('segredo-que-nao-pode-vazar'));

        $this->update->refresh();
        $this->assertSame($expected, $this->update->status);
        $this->assertStringNotContainsString('segredo-que-nao-pode-vazar', (string) $this->update->message);
    }
}
