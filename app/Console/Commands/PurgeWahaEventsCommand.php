<?php

namespace App\Console\Commands;

use App\Models\WahaEvent;
use Illuminate\Console\Command;

class PurgeWahaEventsCommand extends Command
{
    /** O envelope bruto só serve ao job que o lê logo depois de chegar; a mensagem e o ticket já guardam o dado definitivo. */
    public const RETENTION_DAYS = 7;

    protected $signature = 'app:purge-waha-events {--batch=5000 : Quantos eventos apagar por vez}';

    protected $description = 'Apaga os eventos brutos do WAHA (waha_events) com mais de 7 dias, em lotes';

    public function handle(): int
    {
        $batch = max(1, (int) $this->option('batch'));
        $limit = now()->subDays(self::RETENTION_DAYS);
        $deleted = 0;

        // O Postgres não tem `DELETE ... LIMIT`: o lote sai pelos ids, para não segurar um lock longo na tabela que o webhook grava.
        do {
            $ids = WahaEvent::query()->where('received_at', '<', $limit)->orderBy('id')->limit($batch)->pluck('id');
            $deleted += WahaEvent::query()->toBase()->whereIn('id', $ids)->delete();
        } while ($ids->count() === $batch);

        $this->info("Eventos apagados: {$deleted}");

        return self::SUCCESS;
    }
}
