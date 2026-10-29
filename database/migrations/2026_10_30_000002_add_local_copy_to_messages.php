<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Aditiva e nullable: o código da versão anterior continua funcionando com as colunas presentes.
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Cópia local do anexo enviado pelo painel (disco privado). Existe para a espera pela reconexão do WhatsApp
            // e para o reenvio; a rotina de retenção a apaga com 90 dias.
            $table->string('local_path', 255)->nullable()->after('media_size');
            $table->timestamp('media_purged_at')->nullable()->after('local_path');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['local_path', 'media_purged_at']);
        });
    }
};
