<?php

namespace Database\Seeders;

use App\Services\Setup\DefaultTags;
use Illuminate\Database\Seeder;

class TagSeeder extends Seeder
{
    /** O mesmo passo que o agendador roda numa instalação nova: cria o padrão uma vez e nunca recria o que o admin apagou. */
    public function run(): void
    {
        DefaultTags::install();
    }
}
