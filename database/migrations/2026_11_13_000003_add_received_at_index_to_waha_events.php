<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** O índice `(event, received_at)` começa por `event` e não serve ao filtro só por data da limpeza diária. */
    public function up(): void
    {
        Schema::table('waha_events', function (Blueprint $table) {
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::table('waha_events', function (Blueprint $table) {
            $table->dropIndex(['received_at']);
        });
    }
};
