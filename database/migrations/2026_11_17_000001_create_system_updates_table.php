<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_updates', function (Blueprint $table) {
            $table->id();
            $table->string('status')->index();
            $table->string('from_version');
            $table->string('to_version');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('backup_file')->nullable();
            $table->text('previous_compose')->nullable();
            $table->string('previous_version')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('step_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_updates');
    }
};
