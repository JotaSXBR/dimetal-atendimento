<?php

namespace Database\Seeders;

use App\Models\Workflow;
use App\Services\Bot\DefaultWorkflow;
use Illuminate\Database\Seeder;

/** Cria o modelo "Atendimento padrão" uma única vez. Sem versão publicada e sem canal: o admin publica e ativa. */
class DefaultWorkflowSeeder extends Seeder
{
    public function run(): void
    {
        Workflow::query()->firstOrCreate(['name' => DefaultWorkflow::NAME], ['draft' => DefaultWorkflow::graph()]);
    }
}
