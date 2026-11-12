<?php

namespace App\Services\Backup\Restore;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * O que a troca do banco precisa do sistema em volta: modo de manutenção compartilhado (driver `cache` no Redis: app, worker e
 * scheduler enxergam o mesmo estado; as filas não processam e a agenda não roda), `migrate` no banco novo e o aviso aos workers.
 */
class Maintenance
{
    /** Só o driver de cache vale para vários containers; o de arquivo pausaria só o container do job. */
    public function ready(): bool
    {
        return config('app.maintenance.driver') === 'cache';
    }

    public function enter(): void
    {
        Artisan::call('down', ['--retry' => 30]);
    }

    public function leave(): void
    {
        Artisan::call('up');
    }

    /** @throws RestoreRejected */
    public function migrate(): void
    {
        // A conexão do job apontava para o banco que acabou de ser renomeado: reconecta no ativo (o novo).
        DB::purge();

        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            throw new RestoreRejected('As migrações do esquema falharam no banco restaurado.');
        }
    }

    /** Os workers perdem a conexão com o banco antigo e saem; o Coolify os sobe de novo (ver `docker/worker.sh`). */
    public function restartWorkers(): void
    {
        Artisan::call('queue:restart');
    }
}
