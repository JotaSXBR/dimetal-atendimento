<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** De onde veio o nome: `agenda` (salvo no celular da empresa) não é sobrescrito pelo push name das mensagens. */
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('name_source', 10)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('name_source');
        });
    }
};
