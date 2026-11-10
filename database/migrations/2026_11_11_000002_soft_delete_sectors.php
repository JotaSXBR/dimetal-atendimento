<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Setor apagado fica na tabela (`deleted_at`): os tickets antigos continuam mostrando o nome dele e nada some em cascata
 * (hoje `tickets.sector_id` zeraria e `quick_replies.sector_id` apagaria). O nome só precisa ser único entre os não apagados.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sectors', function (Blueprint $table) {
            $table->softDeletes();
            $table->dropUnique('sectors_name_unique');
        });

        DB::statement('CREATE UNIQUE INDEX sectors_name_active_unique ON sectors (name) WHERE deleted_at IS NULL');
    }

    /** Falha se houver dois setores com o mesmo nome (um apagado e outro ativo): resolver à mão antes de desfazer. */
    public function down(): void
    {
        DB::statement('DROP INDEX sectors_name_active_unique');

        Schema::table('sectors', function (Blueprint $table) {
            $table->unique('name');
            $table->dropSoftDeletes();
        });
    }
};
