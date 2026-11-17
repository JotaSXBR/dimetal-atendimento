<?php

namespace App\Jobs;

use App\Enums\UpdateStatus;
use App\Models\SystemUpdate;
use App\Services\Update\ApplyUpdate;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Atualiza o sistema na fila `history` (o backup do começo passa de 10 minutos). Uma tentativa só: repetir não refaria nada. */
class ApplyUpdateJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 1;

    public function __construct(public readonly int $updateId)
    {
        $this->onQueue('history');
    }

    public function handle(ApplyUpdate $apply): void
    {
        $update = SystemUpdate::query()->find($this->updateId);

        $update === null || $apply($update);
    }

    /** Só fecha o que ainda não mexeu no Coolify; de `aplicando` em diante o vigia decide (devolve ou espera o boot). */
    public function failed(Throwable $e): void
    {
        Log::error('Atualização do sistema parou', ['update' => $this->updateId, 'erro' => class_basename($e).': '.$e->getMessage()]);
        $update = SystemUpdate::query()->find($this->updateId);

        if ($update !== null && in_array($update->status, [UpdateStatus::Conferindo, UpdateStatus::Backup], true)) {
            app(ApplyUpdate::class)->fail($update, 'A atualização parou por um erro inesperado antes de mexer no Coolify. Nada foi alterado.');
        }
    }
}
