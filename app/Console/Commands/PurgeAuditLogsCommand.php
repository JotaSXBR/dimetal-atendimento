<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;

class PurgeAuditLogsCommand extends Command
{
    /** A auditoria guarda IP e e-mail digitado (dado pessoal): um ano cobre uma investigação e não fica para sempre. */
    public const RETENTION_DAYS = 365;

    /** O `whereIn` por ids tem um parâmetro por linha e o Postgres aceita no máximo 65535. */
    private const MAX_BATCH = 20000;

    protected $signature = 'app:purge-audit-logs {--batch=5000 : Quantos registros apagar por vez}';

    protected $description = 'Apaga os registros da auditoria do sistema (audit_logs) com mais de 365 dias, em lotes';

    public function handle(): int
    {
        $batch = min(self::MAX_BATCH, max(1, (int) $this->option('batch')));
        $limit = now()->subDays(self::RETENTION_DAYS);
        $deleted = 0;

        // O Postgres não tem `DELETE ... LIMIT`: o lote sai pelos ids, para não segurar um lock longo.
        do {
            $ids = AuditLog::query()->where('created_at', '<', $limit)->orderBy('id')->limit($batch)->pluck('id');
            $deleted += AuditLog::query()->toBase()->whereIn('id', $ids)->delete();
        } while ($ids->count() === $batch);

        $this->info("Registros de auditoria apagados: {$deleted}");

        return self::SUCCESS;
    }
}
