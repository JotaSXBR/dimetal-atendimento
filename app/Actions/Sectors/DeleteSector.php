<?php

namespace App\Actions\Sectors;

use App\Models\QuickReply;
use App\Models\Sector;
use App\Models\Workflow;
use App\Services\Bot\Flow\FlowGraph;
use App\Services\Bot\Flow\NodeType;
use Illuminate\Support\Facades\DB;

/**
 * Apaga um setor sem uso (ele fica na tabela, com `deleted_at`, para os tickets antigos manterem o nome). Recusa quando há ticket
 * aberto, bloco "Transferir" de um fluxo da Automação (rascunho ou última versão publicada) ou resposta rápida do setor.
 */
class DeleteSector
{
    /** @throws SectorInUse */
    public function __invoke(Sector $sector): void
    {
        DB::transaction(function () use ($sector): void {
            // A linha do setor fica travada (FOR UPDATE) antes de conferir o uso: um ticket que esteja sendo movido para ele
            // segura a trava de chave estrangeira e a conferência abaixo o enxerga, em vez de passar por cima.
            $locked = Sector::query()->whereKey($sector->id)->lockForUpdate()->firstOrFail();

            if (($reasons = $this->reasonsInUse($locked)) !== []) {
                throw new SectorInUse('Não dá para apagar: '.implode('; ', $reasons).'.');
            }

            $locked->users()->detach();
            $locked->delete();
        });
    }

    /** @return list<string> */
    private function reasonsInUse(Sector $sector): array
    {
        return array_values(array_filter([
            $sector->hasOpenTickets() ? 'há tickets abertos neste setor (transfira ou encerre antes)' : null,
            ($flows = $this->flowsTransferringTo($sector)) === [] ? null : 'ele é destino de transferência nos fluxos da Automação: '.implode(', ', $flows),
            QuickReply::query()->where('sector_id', $sector->id)->exists() ? 'há respostas rápidas deste setor (mude ou apague em Respostas rápidas)' : null,
        ]));
    }

    /** @return list<string> nomes dos fluxos */
    private function flowsTransferringTo(Sector $sector): array
    {
        $names = Workflow::query()->get()
            ->filter(fn (Workflow $workflow): bool => $this->transfersTo($workflow->draft, $sector->id)
                || ($workflow->latestVersion() !== null && $this->transfersTo($workflow->latestVersion()->graph, $sector->id)))
            ->map(fn (Workflow $workflow): string => $workflow->name)
            ->all();

        return array_values($names);
    }

    /** @param  array<string, mixed>  $graph */
    private function transfersTo(array $graph, int $sectorId): bool
    {
        $flow = FlowGraph::fromArray($graph);

        foreach ($flow->ids() as $id) {
            if ($flow->type($id) === NodeType::Transferir && $flow->data($id)->has('setor_id') && $flow->data($id)->int('setor_id') === $sectorId) {
                return true;
            }
        }

        return false;
    }
}
