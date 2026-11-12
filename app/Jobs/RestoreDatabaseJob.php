<?php

namespace App\Jobs;

use App\Services\Backup\Restore\RestoreDatabase;
use App\Services\Backup\Restore\RestoreRejected;
use App\Services\Backup\Restore\RestoreRequest;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/** Restaura o banco (ou volta ao guardado) na fila `history`, cujo worker aguenta até 900 s; uma por vez (lock no orquestrador). */
class RestoreDatabaseJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 840;

    public int $tries = 1;

    public function __construct(public readonly RestoreRequest $request)
    {
        $this->onQueue('history');
    }

    public function handle(RestoreDatabase $restore): void
    {
        try {
            $restore($this->request);
        } catch (RestoreRejected $e) {
            // Só o lock ocupado chega aqui; o andamento é da outra restauração, então este pedido apenas é descartado.
            Log::warning('Restauração do banco recusada: '.$e->getMessage());
        }
    }
}
