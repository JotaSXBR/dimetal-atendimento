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
use Illuminate\Support\Facades\Process;
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
