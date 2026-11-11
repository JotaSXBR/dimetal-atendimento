<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A nota ganha origem (equipe, bot, no futuro IA); as que não são da equipe não têm autor, então `user_id` passa a aceitar nulo. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_notes', function (Blueprint $table): void {
            $table->string('origin', 20)->default('equipe')->after('user_id');
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Sem autor não há como manter a nota na regra antiga: as automáticas saem junto.
        DB::table('ticket_notes')->whereNull('user_id')->delete();

        Schema::table('ticket_notes', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->dropColumn('origin');
        });
    }
};
