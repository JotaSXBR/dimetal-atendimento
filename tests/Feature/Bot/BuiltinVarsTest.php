<?php

namespace Tests\Feature\Bot;

use App\Models\BotSession;
use App\Services\Bot\BotEngine;
use App\Services\Bot\BotRun;
use App\Services\Bot\Effects\SimulatedEffects;
use App\Services\Bot\Flow\FlowGraph;
use App\Services\Bot\Simulation\SimulationState;
use App\Services\Bot\Vars;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuiltinVarsTest extends TestCase
{
    use RefreshDatabase;

    /** O "Inserir variável" do editor lista `Vars::BUILTIN`: se o bot deixar de resolver uma delas, o cliente receberia `{{x}}` literal. */
    public function test_as_variaveis_do_editor_sao_exatamente_as_que_o_bot_resolve_sem_sessao(): void
    {
        $run = new BotRun(app(BotEngine::class), new BotSession(['vars' => []]), FlowGraph::fromArray([]), new SimulatedEffects(new SimulationState('Teste', 'real')));

        $this->assertSame(array_keys(Vars::BUILTIN), array_keys($run->vars()));
    }
}
