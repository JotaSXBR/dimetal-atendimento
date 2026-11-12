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
    /**
     * O que falta no ambiente para a troca funcionar, ou `null` se está pronto. Só o driver de cache vale para vários containers (o de
     * arquivo pausaria só o container do job), e o estado da manutenção, as sessões e o cache precisam estar fora do banco: o banco
     * troca no meio e a página de andamento, o lock e o andamento têm de continuar de pé.
     */
    public function problem(): ?string
    {
        return match (true) {
            config('app.maintenance.driver') !== 'cache' => 'A restauração pela tela precisa do modo de manutenção compartilhado: defina APP_MAINTENANCE_DRIVER=cache e APP_MAINTENANCE_STORE=redis no app, no worker e no scheduler.',
            $this->usesDatabase(config('app.maintenance.store')) => 'O estado da manutenção está guardado no banco (APP_MAINTENANCE_STORE): use APP_MAINTENANCE_STORE=redis, senão ele some com a troca.',
            config('session.driver') === 'database' => 'As sessões estão no banco (SESSION_DRIVER=database): use SESSION_DRIVER=redis para a página de andamento abrir durante a troca.',
            $this->usesDatabase(config('cache.default')) => 'O cache está no banco (CACHE_STORE=database): use CACHE_STORE=redis; o andamento e o lock da restauração ficam no cache.',
            app()->isDownForMaintenance() => 'O sistema já está em manutenção.',
            default => null,
        };
    }

    public function ready(): bool
    {
        return $this->problem() === null;
    }

    /** O driver do cache `$store` (o padrão se vazio) é o `database`. */
    private function usesDatabase(mixed $store): bool
    {
        $name = is_string($store) && $store !== '' ? $store : config()->string('cache.default');

        return config('cache.stores.'.$name.'.driver') === 'database';
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
