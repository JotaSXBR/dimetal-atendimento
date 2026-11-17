<?php

namespace Tests\Feature\Update;

use App\Models\SystemUpdate;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Dois processos reais: o segundo `start()` espera a trava do primeiro e, ao entrar, enxerga a atualização dele. */
class SystemUpdateConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    public function test_start_em_outro_processo_espera_a_trava_e_e_recusado(): void
    {
        $worker = new Process(
            [PHP_BINARY, base_path('tests/Support/update_worker.php')],
            null,
            ['DB_DATABASE' => (string) config('database.connections.pgsql.database')],
        );

        DB::transaction(function () use ($worker): void {
            $this->assertNotNull(SystemUpdate::start(null, '0.1.0', '0.2.0'));
            $worker->start();

            // Só segue quando o filho está de fato parado na trava (sem sleep fixo).
            $deadline = microtime(true) + 20;

            while (DB::table('pg_locks')->where('locktype', 'advisory')->where('granted', false)->doesntExist()) {
                $this->assertLessThan($deadline, microtime(true), 'O filho não chegou à trava: '.$worker->getErrorOutput());
                usleep(50_000);
            }
        });

        $worker->wait();

        $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput().$worker->getOutput());
        $this->assertSame('REFUSED', trim($worker->getOutput()));
        $this->assertSame(1, SystemUpdate::query()->count());
    }
}
