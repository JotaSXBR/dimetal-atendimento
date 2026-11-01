<?php

namespace App\Policies;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    public function view(User $user, Ticket $ticket): bool
    {
        return Ticket::query()->readableBy($user)->whereKey($ticket->id)->exists();
    }

    public function claim(User $user, Ticket $ticket): bool
    {
        return $user->active
            && $ticket->assigned_user_id === null
            && $ticket->status !== TicketStatus::ENCERRADO
            && $user->canHandleSector($ticket->sector_id);
    }

    public function reply(User $user, Ticket $ticket): bool
    {
        return $user->active
            && $ticket->status === TicketStatus::EM_ATENDIMENTO
            && $ticket->assigned_user_id !== null
            && ($ticket->assigned_user_id === $user->id || $user->canSeeAllSectors())
            && $ticket->channel?->active === true;
    }

    public function close(User $user, Ticket $ticket): bool
    {
        return $user->active
            && $ticket->status !== TicketStatus::ENCERRADO
            && ($ticket->assigned_user_id === $user->id || $user->canSeeAllSectors());
    }

    /**
     * Mandar o ticket para outro setor: o responsável (se a opção dele estiver ligada), supervisor e admin.
     */
    public function transfer(User $user, Ticket $ticket): bool
    {
        return $user->active
            && $ticket->status !== TicketStatus::ENCERRADO
            && ($user->canSeeAllSectors() || ($user->can_transfer && $ticket->assigned_user_id === $user->id));
    }

    /**
     * Escolher quem fica com o ticket: só supervisor e admin.
     */
    public function reassign(User $user, Ticket $ticket): bool
    {
        return $user->active
            && $user->canSeeAllSectors()
            && $ticket->status !== TicketStatus::ENCERRADO;
    }

    /**
     * Tomar para si o ticket que está com outro responsável. O atendente só toma de colega do próprio setor e
     * só com a opção "pode transferir" ligada; supervisor e admin tomam de qualquer um.
     */
    public function takeover(User $user, Ticket $ticket): bool
    {
        return $user->active
            && $ticket->status === TicketStatus::EM_ATENDIMENTO
            && $ticket->assigned_user_id !== null
            && $ticket->assigned_user_id !== $user->id
            && ($user->canSeeAllSectors() || ($user->can_transfer && $ticket->sector_id !== null && $user->canHandleSector($ticket->sector_id)));
    }
}
