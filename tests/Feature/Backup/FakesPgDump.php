<?php

namespace Tests\Feature\Backup;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

/** `pg_dump` e `pg_restore` de mentira: o dump grava `$content` no arquivo pedido em `-f` e cada binário sai com o código dado. */
trait FakesPgDump
{
    private function fakePgDump(int $dumpExit = 0, string $dumpStderr = '', string $content = 'dump-bytes', int $listExit = 0): void
    {
        Process::fake(function (PendingProcess $process) use ($dumpExit, $dumpStderr, $content, $listExit) {
            $command = (array) $process->command;

            if ($command[0] === 'pg_dump') {
                if ($dumpExit === 0) {
                    file_put_contents($command[array_search('-f', $command, true) + 1], $content);
                }

                return Process::result('', $dumpStderr, $dumpExit);
            }

            return Process::result('; listagem do arquivo', $listExit === 0 ? '' : 'pg_restore: erro', $listExit);
        });
    }

    /** Sobras do dumper no diretório temporário. */
    private function leftoverDumps(): array
    {
        return glob(storage_path('app/tmp/backup-*.dump')) ?: [];
    }
}
