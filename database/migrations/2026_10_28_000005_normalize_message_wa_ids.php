<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `wa_message_id` passa a guardar só o id puro da mensagem (trecho após o último `_`), igual ao que
     * `Message::waKey()` grava. Sem volta: o prefixo `{fromMe}_{chat}_` não é recuperável.
     */
    public function up(): void
    {
        DB::statement("update messages set wa_message_id = substring(wa_message_id from '[^_]+$') where wa_message_id like '%\_%'");
    }

    public function down(): void
    {
        //
    }
};
