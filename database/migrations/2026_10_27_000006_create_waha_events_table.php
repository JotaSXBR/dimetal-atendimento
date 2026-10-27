<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waha_events', function (Blueprint $table) {
            $table->id();
            $table->string('waha_event_id')->unique();
            $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event');
            $table->string('session')->nullable();
            $table->json('payload');
            $table->timestamp('received_at');
            $table->timestamps();

            $table->index(['event', 'received_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waha_events');
    }
};
