<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Até qual mensagem do cliente o atendente viu. Por id (e não por horário): `sent_at` tem precisão de segundo e duas mensagens
        // no mesmo segundo se confundiriam. Sem chave estrangeira: é só um número de corte, e a mensagem pode ser apagada pela retenção.
        Schema::table('tickets', function (Blueprint $table) {
            $table->unsignedBigInteger('agent_seen_message_id')->nullable()->after('agent_seen_at');
        });

        // Quem já tinha um visto por horário passa a ter a marca na última mensagem do cliente até aquele instante.
        DB::statement(<<<'SQL'
            update tickets set agent_seen_message_id = (
                select max(m.id) from messages m
                where m.ticket_id = tickets.id and m.direction = 'in' and m.sent_at <= tickets.agent_seen_at
            ) where agent_seen_at is not null
        SQL);
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('agent_seen_message_id');
        });
    }
};
