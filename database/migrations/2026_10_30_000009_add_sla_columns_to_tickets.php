<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Só expande. `waiting_since` é desde quando o cliente espera resposta humana; os demais são o aviso, o vencimento e as marcas
    // de "já avisei". Os índices são parciais, só sobre o que a rotina e as telas leem (abertos e encerrados): medição em
    // docs/superpowers/notes/2026-10-30-sla-risco-benchmark.md (de ~40 ms para ~0,1 ms com 1 milhão de tickets).
    // shortcut: o CREATE INDEX trava a escrita em `tickets` enquanto roda; na produção, usar CONCURRENTLY (fora de transação).
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('waiting_since')->nullable();
            $table->timestamp('sla_warn_at')->nullable();
            $table->timestamp('sla_due_at')->nullable();
            $table->timestamp('sla_warned_at')->nullable();
            $table->timestamp('sla_breached_at')->nullable();
        });

        DB::statement("create index tickets_open_id on tickets (id) where status <> 'encerrado'");
        DB::statement("create index tickets_open_sla_due on tickets (sla_due_at) where status <> 'encerrado' and sla_due_at is not null");
        DB::statement("create index tickets_closed_at on tickets (closed_at desc, id desc) where status = 'encerrado'");
    }

    public function down(): void
    {
        DB::statement('drop index if exists tickets_closed_at');
        DB::statement('drop index if exists tickets_open_sla_due');
        DB::statement('drop index if exists tickets_open_id');

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['waiting_since', 'sla_warn_at', 'sla_due_at', 'sla_warned_at', 'sla_breached_at']);
        });
    }
};
