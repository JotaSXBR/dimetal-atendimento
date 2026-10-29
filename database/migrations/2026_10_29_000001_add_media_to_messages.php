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
            $table->string('media_ref', 255)->nullable()->after('failure_reason');
            $table->string('media_mime', 100)->nullable()->after('media_ref');
            $table->string('media_name', 255)->nullable()->after('media_mime');
            $table->unsignedBigInteger('media_size')->nullable()->after('media_name');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['media_ref', 'media_mime', 'media_name', 'media_size']);
        });
    }
};
