<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No máximo uma linha: a conta Google que recebe os contatos. Os tokens ficam cifrados (cast `encrypted`).
        Schema::create('google_connections', function (Blueprint $table) {
            $table->id();
            $table->string('account_email');
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('status', 20)->default('connected');
            $table->string('last_error', 64)->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_connections');
    }
};
