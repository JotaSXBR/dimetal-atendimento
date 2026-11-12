<?php

namespace Tests\Feature\Backup\Restore;

use App\Services\Backup\Restore\AdminDatabase;
use App\Services\Backup\Restore\RestoreRejected;
use App\Services\Backup\Restore\SideDatabase;
use Carbon\CarbonImmutable;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimeout;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

class SideDatabaseTest extends TestCase
{
    private const PASSWORD = 'S3nh@Secreta';

    private AdminDatabase $admin;

    private SideDatabase $side;

    private string $database;

    private mixed $realPassword;

    protected function setUp(): void
    {
        parent::setUp();

        $this->realPassword = config('database.connections.pgsql.password');
        $this->admin = new AdminDatabase;
        $this->side = new SideDatabase($this->admin);
        // O nome do banco ativo já é único por processo da suíte paralela; a data no futuro evita esbarrar em outro teste.
        $this->database = AdminDatabase::stagingName($this->admin->active(), CarbonImmutable::now()->addYears(5));
    }

    protected function tearDown(): void
    {
        // Os testes de comando trocam a senha da configuração; a limpeza precisa da verdadeira.
        config(['database.connections.pgsql.password' => $this->realPassword]);
        DB::purge('pgsql');
        $this->admin = new AdminDatabase;

        if (isset($this->database) && $this->admin->exists($this->database)) {
            DB::connection('pgsql_admin')->statement('DROP DATABASE "'.$this->database.'" WITH (FORCE)');
        }

        parent::tearDown();
    }

    /** Um banco novo com o esquema mínimo que a verificação exige. */
    private function schema(bool $admin = true, ?string $migration = null, bool $withTickets = true): void
    {
        $this->admin->create($this->database);
        $db = $this->admin->connectionTo($this->database, 'pgsql_side');
        $db->statement('create table users (id serial primary key, role text not null, active boolean not null)');
        $db->statement('create table messages (id serial primary key)');
        $db->statement('create table settings (id serial primary key)');
        $db->statement('create table migrations (id serial primary key, migration text not null, batch int not null)');

        if ($withTickets) {
            $db->statement('create table tickets (id serial primary key)');
        }

        if ($admin) {
            $db->insert("insert into users (role, active) values ('admin', true), ('atendente', true)");
        } else {
            $db->insert("insert into users (role, active) values ('admin', false), ('supervisor', true)");
        }

        $known = basename((string) (glob(database_path('migrations/*.php'))[0] ?? 'x.php'), '.php');
        $db->insert('insert into migrations (migration, batch) values (?, 1)', [$migration ?? $known]);
    }

    private function useSecretPassword(): void
    {
        config(['database.connections.pgsql.password' => self::PASSWORD]);
        DB::purge('pgsql');
        $this->admin = new AdminDatabase;
        $this->side = new SideDatabase($this->admin);
    }

    public function test_restore_monta_o_comando_sem_a_senha(): void
    {
        $this->useSecretPassword();
        Process::fake(fn (PendingProcess $process) => Process::result('', '', 0));

        $this->side->restore($this->database, '/tmp/arquivo.dump');

        Process::assertRan(function (PendingProcess $process): bool {
            $command = (array) $process->command;

            return $command[0] === 'pg_restore'
                && in_array('--no-owner', $command, true)
                && in_array('--exit-on-error', $command, true)
                && in_array($this->database, $command, true)
                && end($command) === '/tmp/arquivo.dump'
                && ! str_contains(implode(' ', $command), self::PASSWORD)
                && ($process->environment['PGPASSWORD'] ?? null) === self::PASSWORD
                && $process->timeout === 600;
        });
    }

    public function test_restore_com_erro_vira_rejected_sem_a_senha(): void
    {
        $this->useSecretPassword();
        Process::fake(fn (PendingProcess $process) => Process::result('', 'pg_restore: error: could not execute query para a senha '.self::PASSWORD, 1));

        try {
            $this->side->restore($this->database, '/tmp/arquivo.dump');
            $this->fail('devia lançar RestoreRejected');
        } catch (RestoreRejected $e) {
            $this->assertStringContainsString('pg_restore falhou', $e->getMessage());
            $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage());
        }
    }

    public function test_mensagem_publica_do_erro_nao_leva_o_stderr_que_pode_ter_dado_de_cliente(): void
    {
        Log::spy();
        $stderr = 'pg_restore: error: COPY failed for table "users": ERROR: x CONTEXT: COPY users, line 1: "joao@cliente.com 5511999990000"';
        Process::fake(fn (PendingProcess $process) => Process::result('', $stderr, 1));

        try {
            $this->side->restore($this->database, '/tmp/arquivo.dump');
            $this->fail('devia lançar RestoreRejected');
        } catch (RestoreRejected $e) {
            $this->assertStringNotContainsString('joao@cliente.com', $e->getMessage());
            $this->assertStringContainsString('código 1', $e->getMessage());
            $this->assertStringContainsString('log', $e->getMessage());
        }

        // O detalhe vai para o log (que só quem opera o servidor lê).
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => str_contains((string) ($context['stderr'] ?? ''), 'COPY users'))->once();
    }

    public function test_restore_com_timeout_vira_rejected(): void
    {
        Process::fake(function (PendingProcess $process) {
            throw new ProcessTimedOutException(new SymfonyTimeout(new SymfonyProcess((array) $process->command), SymfonyTimeout::TYPE_GENERAL), new FakeProcessResult);
        });

        try {
            $this->side->restore($this->database, '/tmp/arquivo.dump');
            $this->fail('devia lançar RestoreRejected');
        } catch (RestoreRejected $e) {
            $this->assertStringContainsString('passou de', $e->getMessage());
        }
    }

    public function test_restore_recusa_nome_de_banco_invalido(): void
    {
        Process::fake();

        $this->expectException(\InvalidArgumentException::class);

        $this->side->restore('a; drop database x', '/tmp/arquivo.dump');
    }

    public function test_verify_passa_com_esquema_e_admin_ativo(): void
    {
        $this->schema();

        $this->side->verify($this->database);

        $this->addToAssertionCount(1);
    }

    public function test_verify_nao_deixa_conexao_aberta_no_banco_novo(): void
    {
        // Achado na prova real: a conexão aberta impedia o renome do banco novo na troca.
        $this->schema();

        $this->side->verify($this->database);

        $row = $this->admin->connection()->selectOne('select count(*) as total from pg_stat_activity where datname = ?', [$this->database]);
        $this->assertSame(0, (int) $row->total);
    }

    /** @return array<string, array{string}> */
    public static function objetosEstranhos(): array
    {
        return [
            'função' => ['create function pwn() returns int language sql as \'select 1\''],
            'gatilho' => ['create function trg() returns trigger language plpgsql as \'begin return new; end\'; create trigger t before insert on settings for each row execute function trg()'],
            'visão' => ['create view v as select 1 as x'],
            'regra' => ['create rule r as on insert to settings do also select 1'],
            'extensão que o sistema não usa' => ['create extension pgcrypto'],
        ];
    }

    #[DataProvider('objetosEstranhos')]
    public function test_verify_recusa_objeto_que_o_sistema_nao_usa(string $sql): void
    {
        // A TOC só mostra rótulos; o catálogo do banco restaurado mostra o que de fato foi criado.
        $this->schema();
        $this->admin->connectionTo($this->database, 'pgsql_side')->unprepared($sql);

        try {
            $this->side->verify($this->database);
            $this->fail('devia lançar RestoreRejected');
        } catch (RestoreRejected $e) {
            $this->assertStringContainsString('objetos que este sistema não usa', $e->getMessage());
        }
    }

    public function test_verify_aceita_a_extensao_pg_trgm_do_sistema(): void
    {
        $this->schema();
        $this->admin->connectionTo($this->database, 'pgsql_side')->unprepared('create extension pg_trgm');

        $this->side->verify($this->database);

        $this->addToAssertionCount(1);
    }

    public function test_verify_recusa_sem_admin_ativo(): void
    {
        $this->schema(admin: false);

        $this->expectException(RestoreRejected::class);
        $this->expectExceptionMessage('administrador ativo');

        $this->side->verify($this->database);
    }

    public function test_verify_recusa_migracao_desconhecida(): void
    {
        $this->schema(migration: '2099_01_01_000000_do_futuro');

        $this->expectException(RestoreRejected::class);
        $this->expectExceptionMessage('versão mais nova');

        $this->side->verify($this->database);
    }

    public function test_verify_recusa_tabela_faltando(): void
    {
        $this->schema(withTickets: false);

        $this->expectException(RestoreRejected::class);
        $this->expectExceptionMessage('tickets');

        $this->side->verify($this->database);
    }
}
