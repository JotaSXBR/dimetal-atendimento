<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Só expande. O rascunho vive no workflow; cada publicação gera uma versão imutável (o grafo nunca é editado).
    public function up(): void
    {
        Schema::create('workflows', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->jsonb('draft');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('workflow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->jsonb('graph');
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->timestamps();

            $table->unique(['workflow_id', 'number']);
        });

        Schema::table('channels', function (Blueprint $table) {
            // Um workflow ativo por canal; nulo = sem bot (o ticket vai direto para a fila).
            $table->foreignId('workflow_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workflow_id');
        });
        Schema::dropIfExists('workflow_versions');
        Schema::dropIfExists('workflows');
    }
};
