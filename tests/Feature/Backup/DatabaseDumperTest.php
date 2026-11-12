<?php

namespace Tests\Feature\Backup;

use App\Services\Backup\DatabaseDumper;
use App\Services\Backup\DumpFailed;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class DatabaseDumperTest extends TestCase
{
    use FakesPgDump;

    private const PASSWORD = 'S3nh@Secreta';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.connections.pgsql.host' => 'pg-beta',
            'database.connections.pgsql.port' => '5432',
            'database.connections.pgsql.database' => 'dimetal',
            'database.connections.pgsql.username' => 'dimetal_user',
            'database.connections.pgsql.password' => self::PASSWORD,
        ]);
        DB::purge('pgsql');
    }

    public function test_monta_o_comando_sem_a_senha(): void
    {
        $this->fakePgDump();

        $path = (new DatabaseDumper)->dump();

        $this->assertSame('dump-bytes', file_get_contents($path));
        Process::assertRan(function (PendingProcess $process) use ($path): bool {
            $command = (array) $process->command;

            return $command[0] === 'pg_dump'
                && array_slice($command, 1, 2) === ['-Fc', '--compress=zstd:3']
                && in_array('pg-beta', $command, true)
                && in_array('dimetal_user', $command, true)
                && in_array('dimetal', $command, true)
                && in_array($path, $command, true)
                && ! str_contains(implode(' ', $command), self::PASSWORD)
                && ($process->environment['PGPASSWORD'] ?? null) === self::PASSWORD;
        });
        Process::assertRan(fn (PendingProcess $process): bool => ((array) $process->command)[0] === 'pg_restore' && in_array('--list', (array) $process->command, true));

        unlink($path);
    }

    public function test_falha_se_pg_dump_sai_com_erro(): void
    {
        $this->fakePgDump(dumpExit: 1, dumpStderr: 'pg_dump: erro: conexão recusada');

        try {
            (new DatabaseDumper)->dump();
            $this->fail('devia lançar DumpFailed');
        } catch (DumpFailed $e) {
            $this->assertStringContainsString('pg_dump', $e->getMessage());
            $this->assertStringContainsString('conexão recusada', $e->getMessage());
        }

        $this->assertSame([], $this->leftoverDumps());
    }

    public function test_falha_se_o_arquivo_fica_vazio(): void
    {
        $this->fakePgDump(content: '');

        $this->expectException(DumpFailed::class);

        try {
            (new DatabaseDumper)->dump();
        } finally {
            $this->assertSame([], $this->leftoverDumps());
        }
    }

    public function test_falha_se_pg_restore_list_falha(): void
    {
        $this->fakePgDump(listExit: 1);

        $this->expectException(DumpFailed::class);

        try {
            (new DatabaseDumper)->dump();
        } finally {
            $this->assertSame([], $this->leftoverDumps());
        }
    }

    public function test_mensagem_de_erro_nao_tem_a_senha(): void
    {
        $this->fakePgDump(dumpExit: 1, dumpStderr: 'FATAL: autenticação falhou para a senha '.self::PASSWORD);

        try {
            (new DatabaseDumper)->dump();
            $this->fail('devia lançar DumpFailed');
        } catch (DumpFailed $e) {
            $this->assertStringNotContainsString(self::PASSWORD, $e->getMessage());
            $this->assertStringNotContainsString('pg_dump -Fc', $e->getMessage());
        }
    }
}
