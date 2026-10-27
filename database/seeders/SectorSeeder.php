<?php

namespace Database\Seeders;

use App\Enums\SectorStatus;
use App\Models\Sector;
use Illuminate\Database\Seeder;

class SectorSeeder extends Seeder
{
    /**
     * Ordem do menu do chatbot.
     */
    public const SECTORS = ['Comercial', 'Financeiro', 'Vendas', 'Diretoria', 'SAC'];

    public function run(): void
    {
        foreach (self::SECTORS as $index => $name) {
            Sector::firstOrCreate(
                ['name' => $name],
                ['menu_order' => $index + 1, 'status' => SectorStatus::ATIVO],
            );
        }
    }
}
