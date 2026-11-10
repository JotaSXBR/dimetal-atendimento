<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('avatar_path')->nullable();
            $table->string('avatar_version', 10)->nullable();
        });

        // Foto de contato não vai para o disco: só a URL do CDN do WhatsApp (que vence) e quando foi checada.
        Schema::table('contacts', function (Blueprint $table) {
            $table->text('avatar_url')->nullable();
            $table->timestamp('avatar_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['avatar_path', 'avatar_version']));
        Schema::table('contacts', fn (Blueprint $table) => $table->dropColumn(['avatar_url', 'avatar_checked_at']));
    }
};
