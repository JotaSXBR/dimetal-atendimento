<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Aditiva e nullable: o código da versão anterior continua funcionando com a coluna presente.
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->string('failure_code', 32)->nullable()->after('failure_reason');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('failure_code');
        });
    }
};
