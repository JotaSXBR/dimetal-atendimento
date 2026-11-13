<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** `CREATE INDEX CONCURRENTLY` não roda dentro de transação; sem ele a criação bloquearia o webhook, que grava nesta tabela. */
    public $withinTransaction = false;

    /** O índice `(event, received_at)` começa por `event` e não serve ao filtro só por data da limpeza diária. */
    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS waha_events_received_at_index ON waha_events (received_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS waha_events_received_at_index');
    }
};
