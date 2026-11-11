<?php

namespace App\Services\Bot\Nodes;

use App\Models\Sector;
use App\Models\User;
use App\Services\Bot\BotRun;
use App\Services\Bot\Flow\NodeData;
use App\Services\Bot\Step;

/**
 * Entrega o ticket à fila: o setor do bloco ou, sem ele, o escolhido no menu. Setor que não recebe mais tickets cai na fila
 * padrão. Com "Atribuir a", tenta passar o ticket à pessoa depois de ele entrar na fila.
 */
final class TransferirNode extends Node
{
    public function enter(BotRun $run, NodeData $data): Step
    {
        $id = $data->has('setor_id') ? $data->int('setor_id') : $run->get('setor_id');
        $sector = is_numeric($id) ? Sector::query()->find((int) $id) : null;

        $assignTo = $data->has('atribuir_a') ? User::query()->find($data->int('atribuir_a')) : null;

        $run->engine->handoff($run->session, 'fluxo', $sector, $assignTo);

        return Step::done();
    }
}
