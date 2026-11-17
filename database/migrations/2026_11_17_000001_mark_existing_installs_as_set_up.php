<?php

use App\Models\User;
use App\Services\Setup\DefaultSectors;
use App\Services\Setup\DefaultTags;
use App\Services\Setup\SetupState;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Quem já tem usuário é instalação antiga: pula o assistente e recebe os padrões que o agendador instalaria. Banco vazio fica sem a marca. */
    public function up(): void
    {
        if (! User::query()->exists()) {
            return;
        }

        SetupState::markConcluded();
        DefaultSectors::install();
        DefaultTags::install();
    }

    public function down(): void {}
};
