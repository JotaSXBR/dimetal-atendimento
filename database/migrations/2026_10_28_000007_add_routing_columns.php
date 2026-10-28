<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LOCAL_TIMEZONE = 'America/Sao_Paulo';

    // Só expande: o código da versão anterior continua funcionando com as colunas presentes. `protocol` fica
    // nulo-permitido até a versão nova rodar em todos os recursos; o NOT NULL vem num PR seguinte.
    public function up(): void
    {
        Schema::create('ticket_sequences', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->string('protocol', 16)->nullable()->unique()->after('id');
            $table->string('closed_reason', 20)->nullable()->after('closed_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('can_transfer')->default(true)->after('active');
        });

        $this->backfillProtocols();
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('can_transfer'));
        Schema::table('tickets', fn (Blueprint $table) => $table->dropColumn(['protocol', 'closed_reason']));
        Schema::dropIfExists('ticket_sequences');
    }

    /**
     * Protocolo dos tickets que já existem: ano de `opened_at` no fuso de São Paulo, ordem de `id`.
     */
    private function backfillProtocols(): void
    {
        $local = "(opened_at at time zone 'UTC') at time zone '".self::LOCAL_TIMEZONE."'";

        DB::statement("
            with numbered as (
                select id,
                       extract(year from {$local})::int as y,
                       row_number() over (partition by extract(year from {$local}) order by id) as n
                from tickets
            )
            update tickets
            set protocol = numbered.y || '-' || lpad(numbered.n::text, 6, '0')
            from numbered
            where tickets.id = numbered.id
        ");

        DB::statement("
            insert into ticket_sequences (year, last)
            select extract(year from {$local})::int, count(*) from tickets group by 1
        ");
    }
};
