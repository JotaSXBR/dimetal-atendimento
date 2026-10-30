<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Textos prontos chamados por `/atalho` na barra de mensagem. Sem setor, valem para todos.
        Schema::create('quick_replies', function (Blueprint $table) {
            $table->id();
            $table->string('shortcut', 30)->unique();
            $table->string('body', 1000);
            $table->foreignId('sector_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quick_replies');
    }
};
