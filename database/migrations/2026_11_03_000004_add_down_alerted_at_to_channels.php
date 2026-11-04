<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            // Quando a queda do episódio atual foi anunciada (faixa, som e sino); vazio enquanto conectado ou ainda na tolerância.
            $table->timestamp('down_alerted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropColumn('down_alerted_at');
        });
    }
};
