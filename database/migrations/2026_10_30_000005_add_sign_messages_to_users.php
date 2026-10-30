<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A escolha de cada atendente de assinar (ou não) as mensagens que envia pelo painel.
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('sign_messages')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('sign_messages');
        });
    }
};
