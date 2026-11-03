<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            // Desde quando o canal está fora de WORKING; vazio enquanto conectado. Serve para recuperar o que passou na queda.
            $table->timestamp('down_since')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table): void {
            $table->dropColumn('down_since');
        });
    }
};
