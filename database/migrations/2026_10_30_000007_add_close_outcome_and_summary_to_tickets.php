<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preenchidos quando um atendente encerra pelo painel; encerramento automático e fusão ficam sem eles.
        Schema::table('tickets', function (Blueprint $table) {
            $table->string('close_outcome', 20)->nullable();
            $table->string('close_summary', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['close_outcome', 'close_summary']);
        });
    }
};
