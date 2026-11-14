<?php

namespace App\Services\Inbox;

use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Collection;

/** O que a Fila decide a partir da lista que mostra: se o card diz o setor e o resumo do dia da coluna vazia. */
final class QueueOverview
{
    /**
     * O setor no card só ajuda quando há mais de um setor recebendo tickets e a lista mostrada mistura setores: repetir o mesmo em
     * todo card só polui.
     *
     * @param  Collection<int, Ticket>  $list
     */
    public static function showSector(Collection $list): bool
    {
        return $list->pluck('sector_id')->unique()->count() > 1 && Sector::query()->receivingTickets()->count() >= 2;
    }

    /**
     * O resumo da Fila sem ticket aberto: quantos aguardam, quantos são do usuário e o ticket com o prazo de resposta mais perto (ou já
     * vencido). Só olha tickets que o usuário vê, sem os filtros da lista: é um retrato do dia, não da busca. `null` com ticket aberto.
     *
     * @param  array{pendentes: int, aguardando: int, meus: int}  $counts
     * @return array{waiting: int, mine: int, nearest: ?Ticket}|null
     */
    public static function summary(?Ticket $open, User $user, array $counts): ?array
    {
        if ($open !== null) {
            return null;
        }

        return [
            'waiting' => $counts['aguardando'],
            'mine' => $counts['meus'],
            'nearest' => Ticket::query()->open()->visibleTo($user)->whereNotNull('waiting_since')->whereNotNull('sla_due_at')
                ->with('contact')->orderBy('sla_due_at')->orderBy('id')->first(),
        ];
    }
}
