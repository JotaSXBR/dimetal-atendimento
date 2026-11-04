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
            // A mensagem que esta responde: o id do WhatsApp dela e um trecho guardado (autor, tipo e até 200 caracteres),
            // para a citação aparecer mesmo quando a original saiu da página ou o WAHA não a devolve.
            $table->string('quoted_wa_message_id', 255)->nullable()->after('revoked_at');
            $table->json('quoted_snapshot')->nullable()->after('quoted_wa_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['quoted_wa_message_id', 'quoted_snapshot']);
        });
    }
};
