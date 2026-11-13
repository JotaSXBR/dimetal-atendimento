<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Auditoria do sistema (acesso, usuários, backup e restauração, configurações). A de tickets é a `ticket_events`. */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // O rótulo guarda nome e e-mail do momento: sobrevive ao usuário apagado e cobre a falha de login (sem usuário).
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_label')->nullable();
            $table->string('action', 60);
            $table->string('subject')->nullable();
            $table->string('ip', 45)->nullable();
            $table->json('detail')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['action', 'created_at']);
            // O filtro por usuário da tela e o `ON DELETE SET NULL` da chave estrangeira.
            $table->index(['actor_user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
