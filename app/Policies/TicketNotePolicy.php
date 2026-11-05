<?php

namespace App\Policies;

use App\Models\TicketNote;
use App\Models\User;

class TicketNotePolicy
{
    /** Só o autor apaga a própria nota; supervisor e admin também não. */
    public function delete(User $user, TicketNote $note): bool
    {
        return $user->active && $note->user_id === $user->id;
    }
}
