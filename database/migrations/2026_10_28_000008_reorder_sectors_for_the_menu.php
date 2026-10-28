<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ORIGINAL = ['Comercial', 'Financeiro', 'Vendas', 'Diretoria', 'SAC'];

    private const ORDERED = ['Vendas', 'Financeiro', 'Comercial', 'SAC', 'Diretoria'];

    /**
     * Só reordena se os setores ainda estiverem na ordem do seeder original: uma ordem alterada depois pela
     * gestão de setores não é desfeita.
     */
    public function up(): void
    {
        $current = DB::table('sectors')->orderBy('menu_order')->pluck('name')->all();

        if ($current !== self::ORIGINAL) {
            return;
        }

        foreach (self::ORDERED as $position => $name) {
            DB::table('sectors')->where('name', $name)->update(['menu_order' => $position + 1]);
        }
    }

    public function down(): void
    {
        //
    }
};
