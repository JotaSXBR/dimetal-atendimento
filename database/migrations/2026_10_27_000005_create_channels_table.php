<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('waha_session')->unique();
            $table->string('phone')->nullable();
            $table->string('status')->default('STOPPED');
            $table->timestamp('last_event_at')->nullable();
            $table->unsignedSmallInteger('backfill_days')->default(0);
            $table->timestamp('backfill_last_run_at')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
