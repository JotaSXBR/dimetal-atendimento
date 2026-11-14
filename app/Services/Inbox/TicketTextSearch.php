<?php

namespace App\Services\Inbox;

use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;

/**
 * Busca de texto da Fila e do Histórico: nome, apelido, e-mail, empresa ou telefone do contato, ou o protocolo do ticket. `%`, `_` e `\`
 * valem como texto; termo vazio (ou só espaços) não filtra. Só estreita a consulta: a visibilidade do usuário é outro escopo, aplicado antes.
 */
final class TicketTextSearch
{
    /** @param  Builder<Ticket>  $query */
    public static function apply(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        $query->where(fn (Builder $match) => $match
            ->whereHas('contact', fn (Builder $contact) => $contact->search($term))
            ->orWhereRaw('tickets.protocol ilike ?', [$like]));
    }
}
