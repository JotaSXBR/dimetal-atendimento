<?php

namespace App\Services\Bot\Simulation;

use App\Models\BotSession;
use App\Services\Bot\BotEngine;
use App\Services\Bot\BotRun;
use App\Services\Bot\Effects\SimulatedEffects;
use App\Services\Bot\Flow\FlowGraph;

/**
 * Roda o motor de verdade sobre um rascunho, com efeitos que só registram: nada é gravado nem enviado. A sessão é um modelo em
 * memória que nunca chega ao banco; o estado da conversa fica em `SimulationState`.
 */
final class BotSimulator
{
    public function __construct(private readonly BotEngine $engine) {}

    /** @param  array<string, mixed>  $graph */
    public function start(array $graph, string $name, string $clock): SimulationState
    {
        $state = new SimulationState($name, $clock);
        $state->session['node_id'] = FlowGraph::fromArray($graph)->start();

        $run = $this->run($graph, $state);
        $this->engine->begin($run);

        return $this->finish($state, $run);
    }

    /** @param  array<string, mixed>  $graph */
    public function reply(array $graph, SimulationState $state, string $text): SimulationState
    {
        if (! $this->active($state)) {
            return $state;
        }

        $state->add('cliente', $text);
        $run = $this->run($graph, $state);
        $id = $run->session->node_id;

        // O rascunho pode ter mudado entre duas respostas: sem o bloco em que a conversa esperava, vai para a fila como no motor
        // quando o fluxo não tem saída.
        if ($id === null || $run->flow->type($id)?->waits() !== true) {
            $this->engine->deliver($run, 'erros');
        } else {
            $this->engine->reply($run, $text);
        }

        return $this->finish($state, $run);
    }

    /**
     * O cliente ficou em silêncio até o prazo do bloco que espera.
     *
     * @param  array<string, mixed>  $graph
     */
    public function silence(array $graph, SimulationState $state): SimulationState
    {
        if (! $this->active($state) || $state->session['waiting'] === null) {
            return $state;
        }

        $run = $this->run($graph, $state);
        $this->engine->deliver($run, 'timeout');

        return $this->finish($state, $run);
    }

    private function active(SimulationState $state): bool
    {
        return $state->outcome === null && $state->session['state'] === BotSession::ACTIVE;
    }

    /** @param  array<string, mixed>  $graph */
    private function run(array $graph, SimulationState $state): BotRun
    {
        return new BotRun($this->engine, $state->toSession(), FlowGraph::fromArray($graph), new SimulatedEffects($state));
    }

    private function finish(SimulationState $state, BotRun $run): SimulationState
    {
        $state->capture($run->session);

        return $state;
    }
}
