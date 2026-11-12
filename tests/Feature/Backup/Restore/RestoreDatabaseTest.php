<?php

namespace Tests\Feature\Backup\Restore;

use App\Enums\ChannelStatus;
use App\Enums\UserRole;
use App\Jobs\RecoverMessagesJob;
use App\Jobs\RestoreDatabaseJob;
use App\Models\Channel;
use App\Models\User;
use App\Notifications\BackupRestored;
use App\Services\Backup\BackupFile;
use App\Services\Backup\BackupKind;
use App\Services\Backup\DumpFailed;
use App\Services\Backup\Restore\AdminDatabase;
use App\Services\Backup\Restore\DumpInfo;
use App\Services\Backup\Restore\DumpInspector;
use App\Services\Backup\Restore\InvalidDump;
use App\Services\Backup\Restore\Maintenance;
use App\Services\Backup\Restore\RestoreDatabase;
use App\Services\Backup\Restore\RestoreProgress;
use App\Services\Backup\Restore\RestoreRejected;
use App\Services\Backup\Restore\RestoreRequest;
use App\Services\Backup\Restore\RestoreSource;
use App\Services\Backup\Restore\SideDatabase;
use App\Services\Backup\Restore\StoredDatabase;
use App\Services\Backup\Restore\SwapFailed;
use App\Services\Backup\RunBackup;
use App\Settings\BackupStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

/** O orquestrador com os colaboradores trocados por mocks que anotam a ordem das chamadas. */
class RestoreDatabaseTest extends TestCase
{
    use RefreshDatabase;

    private const NOW = '2026-11-12 18:00:00';

    private const STAGING = 'dimetal_restaurando_202611121500';

    private const ARCHIVE = 'dimetal_antes_202611121500';

    /** @var list<string> */
    private array $calls = [];

    private MockInterface $sources;

    private MockInterface $inspector;

    private MockInterface $backup;

    private MockInterface $admin;

    private MockInterface $side;

    private MockInterface $maintenance;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(self::NOW);
        config(['app.display_timezone' => 'America/Sao_Paulo']);
        Queue::fake();
        Notification::fake();

        $note = function (string $call, mixed $return = null): \Closure {
            return function () use ($call, $return) {
                $this->calls[] = $call;

                return $return;
            };
        };

        $this->sources = $this->mock(RestoreSource::class);
        $this->sources->shouldReceive('fetch')->andReturnUsing(function () {
            $this->calls[] = 'fetch';

            return ['path' => '/tmp/restore-x.dump', 'name' => 'dimetal-2026-11-12-1000-manual.dump'];
        })->byDefault();
        $this->sources->shouldReceive('discard')->andReturnUsing($note('discard'))->byDefault();

        $this->inspector = $this->mock(DumpInspector::class);
        $this->inspector->shouldReceive('inspect')->andReturnUsing(function (string $path, string $name) {
            $this->calls[] = 'inspect';

            return new DumpInfo($name, CarbonImmutable::parse('2026-11-12 16:00:00', 'UTC'), 'dimetal', '18.6', '18.6', 'zstd', 269, 1000);
        })->byDefault();

        $this->backup = $this->mock(RunBackup::class);
        $this->backup->shouldReceive('__invoke')->andReturnUsing(function (BackupKind $kind) {
            $this->calls[] = 'backup:'.$kind->value;

            return new BackupFile('dimetal-2026-11-12-1500-antes.dump', $kind, CarbonImmutable::now(), 10);
        })->byDefault();

        $this->admin = $this->mock(AdminDatabase::class);
        $this->admin->shouldReceive('active')->andReturn('dimetal')->byDefault();
        $this->admin->shouldReceive('exists')->andReturn(false)->byDefault();
        $this->admin->shouldReceive('create')->andReturnUsing(fn (string $name) => $this->calls[] = 'create:'.$name)->byDefault();
        $this->admin->shouldReceive('drop')->andReturnUsing(fn (string $name) => $this->calls[] = 'drop:'.$name)->byDefault();
        $this->admin->shouldReceive('swap')->andReturnUsing(fn (string $active, string $incoming, string $archive) => $this->calls[] = "swap:$active,$incoming,$archive")->byDefault();
        $this->admin->shouldReceive('stored')->andReturn([])->byDefault();

        $this->side = $this->mock(SideDatabase::class);
        $this->side->shouldReceive('restore')->andReturnUsing(fn (string $db) => $this->calls[] = 'restore:'.$db)->byDefault();
        $this->side->shouldReceive('verify')->andReturnUsing(fn (string $db) => $this->calls[] = 'verify:'.$db)->byDefault();

        $this->maintenance = $this->mock(Maintenance::class);
        $this->maintenance->shouldReceive('enter')->andReturnUsing($note('enter'))->byDefault();
        $this->maintenance->shouldReceive('leave')->andReturnUsing($note('leave'))->byDefault();
        $this->maintenance->shouldReceive('migrate')->andReturnUsing($note('migrate'))->byDefault();
        $this->maintenance->shouldReceive('restartWorkers')->andReturnUsing($note('restart'))->byDefault();

        User::factory()->create(['role' => UserRole::ADMIN]);
    }

    private function request(string $mode = 'restore', string $source = 'bucket:dimetal-2026-11-12-1000-manual.dump'): RestoreRequest
    {
        return new RestoreRequest($mode, $source, 'Jessé <jotasx@example.com>');
    }

    private function run_(?RestoreRequest $request = null): void
    {
        app(RestoreDatabase::class)($request ?? $this->request());
    }

    private function state(): array
    {
        return app(RestoreProgress::class)->get();
    }

    public function test_restaura_na_ordem_do_spec(): void
    {
        Log::spy();

        $this->run_();

        $this->assertSame([
            'fetch', 'inspect', 'backup:antes', 'create:'.self::STAGING, 'restore:'.self::STAGING, 'verify:'.self::STAGING,
            'enter', 'swap:dimetal,'.self::STAGING.','.self::ARCHIVE, 'migrate', 'restart', 'leave', 'discard',
        ], $this->calls);
        $state = $this->state();
        $this->assertSame('done', $state['status']);
        $this->assertSame(['done', 'done', 'done', 'done', 'done', 'done', 'done', 'done'], array_column($state['steps'], 'state'));
        $this->assertSame(['inspect', 'safety', 'restore', 'verify', 'swap', 'migrate', 'resume', 'recover'], array_column($state['steps'], 'key'));
        $this->assertSame('dimetal-2026-11-12-1000-manual.dump', BackupStatus::lastRestore()['file'] ?? null);
        $this->assertSame('Jessé <jotasx@example.com>', BackupStatus::lastRestore()['actor'] ?? null);
    }

    public function test_arquivo_recusado_nao_cria_banco_nem_entra_em_manutencao(): void
    {
        $this->inspector->shouldReceive('inspect')->andThrow(new InvalidDump('Este arquivo não é um backup deste sistema.'));

        $this->run_();

        $this->assertSame(['fetch', 'discard'], $this->calls);
        $this->assertSame('failed', $this->state()['status']);
        $this->assertSame('Este arquivo não é um backup deste sistema.', $this->state()['message']);
    }

    public function test_falha_no_backup_de_seguranca_aborta(): void
    {
        $this->backup->shouldReceive('__invoke')->andThrow(new DumpFailed('pg_dump falhou (código 1).'));

        $this->run_();

        $this->assertNotContains('create:'.self::STAGING, $this->calls);
        $this->assertSame('failed', $this->state()['status']);
        $this->assertSame('safety', collect($this->state()['steps'])->firstWhere('state', 'failed')['key']);
    }

    public function test_backup_em_andamento_aborta_com_mensagem_clara(): void
    {
        $this->backup->shouldReceive('__invoke')->andReturn(null);

        $this->run_();

        $this->assertNotContains('create:'.self::STAGING, $this->calls);
        $this->assertStringContainsString('backup em andamento', $this->state()['message']);
    }

    public function test_falha_na_restauracao_apaga_o_banco_novo(): void
    {
        $this->side->shouldReceive('restore')->andThrow(new RestoreRejected('O pg_restore falhou (código 1).'));
        $this->admin->shouldReceive('exists')->with(self::STAGING)->andReturn(true);

        $this->run_();

        $this->assertContains('drop:'.self::STAGING, $this->calls);
        $this->assertNotContains('enter', $this->calls);
        $this->assertSame('failed', $this->state()['status']);
    }

    public function test_falha_na_verificacao_apaga_o_banco_novo_e_nao_troca(): void
    {
        $this->side->shouldReceive('verify')->andThrow(new RestoreRejected('O backup não tem nenhum administrador ativo.'));
        $this->admin->shouldReceive('exists')->with(self::STAGING)->andReturn(true);

        $this->run_();

        $this->assertContains('drop:'.self::STAGING, $this->calls);
        $this->assertNotContains('enter', $this->calls);
        $this->assertSame([], array_filter($this->calls, fn (string $call): bool => str_starts_with($call, 'swap:')));
        $this->assertStringContainsString('administrador', $this->state()['message']);
    }

    public function test_falha_na_troca_sai_da_manutencao_e_mantem_o_banco_antigo(): void
    {
        $this->admin->shouldReceive('swap')->andThrow(new SwapFailed('Não foi possível trocar o banco; o banco anterior continua ativo.'));
        $this->admin->shouldReceive('exists')->with(self::STAGING)->andReturn(true);

        $this->run_();

        $this->assertContains('enter', $this->calls);
        $this->assertContains('leave', $this->calls);
        $this->assertNotContains('migrate', $this->calls);
        $this->assertContains('drop:'.self::STAGING, $this->calls);
        $this->assertSame('failed', $this->state()['status']);
    }

    public function test_falha_na_migracao_desfaz_a_troca(): void
    {
        $this->maintenance->shouldReceive('migrate')->andThrow(new RestoreRejected('As migrações do esquema falharam.'));
        $this->admin->shouldReceive('exists')->with(self::STAGING)->andReturn(true);

        $this->run_();

        $swaps = array_values(array_filter($this->calls, fn (string $call): bool => str_starts_with($call, 'swap:')));
        $this->assertSame(['swap:dimetal,'.self::STAGING.','.self::ARCHIVE, 'swap:dimetal,'.self::ARCHIVE.','.self::STAGING], $swaps);
        $this->assertContains('leave', $this->calls);
        $this->assertContains('drop:'.self::STAGING, $this->calls);
        $this->assertSame('failed', $this->state()['status']);
        $this->assertNull(BackupStatus::lastRestore());
    }

    public function test_sempre_sai_da_manutencao_mesmo_com_erro_inesperado(): void
    {
        $this->maintenance->shouldReceive('migrate')->andThrow(new RuntimeException('falha com a senha S3nh@Secreta'));
        $this->admin->shouldReceive('exists')->with(self::STAGING)->andReturn(true);

        $this->run_();

        $this->assertContains('leave', $this->calls);
        $this->assertSame('failed', $this->state()['status']);
        $this->assertStringNotContainsString('S3nh@Secreta', $this->state()['message']);
        $this->assertStringContainsString('RuntimeException', $this->state()['message']);
    }

    public function test_segunda_restauracao_com_o_lock_ocupado_e_recusada(): void
    {
        $lock = Cache::lock('restore-database', 60);
        $this->assertTrue($lock->get());

        try {
            $this->run_();
            $this->fail('devia lançar RestoreRejected');
        } catch (RestoreRejected $e) {
            $this->assertStringContainsString('em andamento', $e->getMessage());
        }

        $this->assertSame([], $this->calls);
        $this->assertSame('idle', $this->state()['status']);
        $lock->release();
    }

    public function test_recupera_so_dos_canais_ativos_e_conectados_desde_o_backup_ate_72h(): void
    {
        $ok = Channel::factory()->connected()->create(['waha_session' => 'a']);
        Channel::factory()->create(['waha_session' => 'b', 'status' => ChannelStatus::STOPPED]);
        Channel::factory()->connected()->create(['waha_session' => 'c', 'active' => false]);

        $this->run_();

        Queue::assertPushed(RecoverMessagesJob::class, 1);
        Queue::assertPushed(RecoverMessagesJob::class, fn (RecoverMessagesJob $job): bool => $job->channelId === $ok->id
            && $job->from->equalTo(CarbonImmutable::parse('2026-11-12 16:00:00', 'UTC'))
            && $job->to->equalTo(CarbonImmutable::parse(self::NOW, 'UTC'))
            && $job->notifyAlways);
    }

    public function test_backup_antigo_recupera_no_maximo_72h(): void
    {
        Channel::factory()->connected()->create(['waha_session' => 'a']);
        $this->inspector->shouldReceive('inspect')->andReturn(new DumpInfo('x.dump', CarbonImmutable::parse('2026-11-01 10:00:00', 'UTC'), 'dimetal', '18.6', '18.6', 'zstd', 1, 1));

        $this->run_();

        Queue::assertPushed(RecoverMessagesJob::class, fn (RecoverMessagesJob $job): bool => $job->from->equalTo(CarbonImmutable::parse(self::NOW, 'UTC')->subHours(72)));
    }

    public function test_switch_nao_restaura_nem_faz_backup_de_seguranca(): void
    {
        $stored = new StoredDatabase('dimetal_antes_202611110900', CarbonImmutable::parse('2026-11-11 09:00:00', 'America/Sao_Paulo'), 1000);
        $this->admin->shouldReceive('stored')->andReturn([$stored]);
        Channel::factory()->connected()->create(['waha_session' => 'a']);

        $this->run_($this->request('switch', 'dimetal_antes_202611110900'));

        $this->assertSame(['enter', 'swap:dimetal,dimetal_antes_202611110900,'.self::ARCHIVE, 'migrate', 'restart', 'leave'], $this->calls);
        $this->assertSame('done', $this->state()['status']);
        $this->assertSame(['swap', 'migrate', 'resume', 'recover'], array_column($this->state()['steps'], 'key'));
        Queue::assertPushed(RecoverMessagesJob::class, fn (RecoverMessagesJob $job): bool => $job->from->equalTo($stored->createdAt));
    }

    public function test_switch_com_nome_forjado_e_recusado(): void
    {
        $this->admin->shouldReceive('stored')->andReturn([new StoredDatabase('dimetal_antes_202611110900', CarbonImmutable::now(), 1)]);

        $this->run_($this->request('switch', 'postgres'));

        $this->assertSame([], $this->calls);
        $this->assertSame('failed', $this->state()['status']);
        $this->assertStringContainsString('não existe mais', $this->state()['message']);
    }

    public function test_log_e_aviso_aos_admins_no_fim(): void
    {
        Log::spy();

        $this->run_();

        Log::shouldHaveReceived('notice')->withArgs(fn (string $message, array $context): bool => ($context['result'] ?? null) === 'ok'
            && ($context['actor'] ?? null) === 'Jessé <jotasx@example.com>'
            && ($context['source'] ?? null) === 'bucket:dimetal-2026-11-12-1000-manual.dump')->once();
        Notification::assertSentTo(User::query()->where('role', UserRole::ADMIN->value)->first(), BackupRestored::class);
    }

    public function test_o_job_roda_na_fila_history_e_chama_o_orquestrador(): void
    {
        $request = $this->request();
        $restore = $this->mock(RestoreDatabase::class);
        $restore->shouldReceive('__invoke')->once()->with($request);

        $job = new RestoreDatabaseJob($request);

        $this->assertSame('history', $job->queue);
        $this->assertSame(840, $job->timeout);
        $this->assertSame(1, $job->tries);
        $job->handle($restore);
    }

    public function test_o_job_nao_estoura_quando_a_restauracao_e_recusada_por_lock(): void
    {
        $restore = $this->mock(RestoreDatabase::class);
        $restore->shouldReceive('__invoke')->andThrow(new RestoreRejected('Já há uma restauração em andamento.'));

        (new RestoreDatabaseJob($this->request()))->handle($restore);

        $this->addToAssertionCount(1);
    }

    public function test_o_lock_e_liberado_ao_terminar_com_ou_sem_erro(): void
    {
        $this->inspector->shouldReceive('inspect')->once()->andThrow(new InvalidDump('x'));
        $this->run_();

        $lock = Cache::lock('restore-database', 60);
        $this->assertTrue($lock->get());
        $lock->release();
        Mockery::close();
    }
}
