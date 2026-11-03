<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_edits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained()->cascadeOnDelete();
            // O texto que a mensagem tinha antes desta edição; `messages.body` guarda sempre a versão mais nova.
            $table->text('previous_body');
            $table->timestamp('edited_at');
            // O id do evento de edição do WhatsApp: o mesmo evento repetido (mesmo tarde) não vale duas vezes.
            $table->string('action_id')->nullable()->unique();
            $table->timestamps();

            $table->index('message_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_edits');
    }
};
