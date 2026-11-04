<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_reactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            // Quem reagiu: `contact` (o cliente) ou `me` (o número da empresa, pelo celular). Uma reação por autor e mensagem.
            $table->string('by', 10);
            $table->string('emoji', 32);
            $table->timestamp('reacted_at');
            $table->timestamps();

            $table->unique(['message_id', 'by']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_reactions');
    }
};
