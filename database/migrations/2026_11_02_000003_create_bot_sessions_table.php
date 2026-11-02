<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('workflow_version_id')->constrained()->restrictOnDelete();
            $table->string('node_id', 40)->nullable();
            $table->jsonb('vars');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('waiting', 10)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('state', 12)->default('ativa');
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->timestamps();
        });

        // A rotina de minuto só olha as sessões ativas com prazo.
        DB::statement("create index bot_sessions_active_expires on bot_sessions (expires_at) where state = 'ativa'");
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_sessions');
    }
};
