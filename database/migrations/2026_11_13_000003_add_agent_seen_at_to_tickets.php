<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Quando o responsável viu a conversa pela última vez: as não lidas são as mensagens do cliente depois disso.
        Schema::table('tickets', function (Blueprint $table) {
            $table->timestamp('agent_seen_at')->nullable();
        });

        // Quem já está na Fila não nasce com "todas as mensagens não lidas": os abertos contam como vistos até agora.
        DB::table('tickets')->where('status', '<>', 'encerrado')->update(['agent_seen_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('agent_seen_at');
        });
    }
};
