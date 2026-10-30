<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Só expande: o índice acelera `ilike '%termo%'` na busca do histórico. Medição em docs/superpowers/notes/2026-10-30-busca-de-texto-benchmark.md.
    public function up(): void
    {
        DB::statement('create extension if not exists pg_trgm');
        DB::statement('create index if not exists messages_body_trgm on messages using gin (body gin_trgm_ops)');
    }

    // A extensão fica: pode ser de outro uso, e remover o índice basta para desfazer.
    public function down(): void
    {
        DB::statement('drop index if exists messages_body_trgm');
    }
};
