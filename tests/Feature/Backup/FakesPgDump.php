<?php

namespace Tests\Feature\Backup;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

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

    /** Uma pasta temporária própria por teste: os testes rodam em paralelo e `storage/app/tmp` seria compartilhada entre processos. */
    private function isolateTemporaryDumps(): void
    {
        $directory = sys_get_temp_dir().'/dimetal-backup-test-'.Str::random(12);
        config(['backup.tmp_path' => $directory]);
        $this->beforeApplicationDestroyed(fn () => File::deleteDirectory($directory));
    }

    /** Sobras do dumper no diretório temporário deste teste. */
    private function leftoverDumps(): array
    {
        return glob(config()->string('backup.tmp_path').'/backup-*.dump') ?: [];
    }
}
