<?php

namespace App\Policies;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;

class TicketPolicy
{
    public function view(User $user, Ticket $ticket): bool
    {
        return Ticket::query()->visibleTo($user)->whereKey($ticket->id)->exists();
    }

    public function claim(User $user, Ticket $ticket): bool
    {
        return $user->active
            && $ticket->assigned_user_id === null
            && $ticket->status !== TicketStatus::ENCERRADO;
    }

    public function reply(User $user, Ticket $ticket): bool
    {
        return $user->active
            && $ticket->status === TicketStatus::EM_ATENDIMENTO
            && $ticket->assigned_user_id !== null
            && ($ticket->assigned_user_id === $user->id || $user->canSeeAllSectors());
    }

    public function close(User $user, Ticket $ticket): bool
    {
        return $user->active
            && $ticket->status !== TicketStatus::ENCERRADO
            && ($ticket->assigned_user_id === $user->id || $user->canSeeAllSectors());
    }
}
