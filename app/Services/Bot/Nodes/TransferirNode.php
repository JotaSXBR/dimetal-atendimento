<?php

namespace App\Services\Bot\Nodes;

use App\Models\Sector;
use App\Services\Bot\BotRun;
use App\Services\Bot\Step;

/** Entrega o ticket à fila: o setor do bloco ou, sem ele, o escolhido no menu. Setor que não recebe mais tickets cai na fila padrão. */
final class TransferirNode extends Node
{
    public function enter(BotRun $run, array $data): Step
    {
        $id = $data['setor_id'] ?? $run->get('setor_id');
        $sector = is_numeric($id) ? Sector::query()->find((int) $id) : null;

        $run->engine->handoff($run->session, 'fluxo', $sector);

        return Step::done();
    }
}
