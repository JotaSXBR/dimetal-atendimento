<?php

namespace App\Policies;

use App\Enums\NoteOrigin;
use App\Models\TicketNote;
use App\Models\User;

class TicketNotePolicy
{
    /** Só o autor apaga a própria nota (supervisor e admin também não); nota automática ninguém apaga. */
    public function delete(User $user, TicketNote $note): bool
    {
        return $user->active && $note->origin === NoteOrigin::EQUIPE && $note->user_id === $user->id;
    }
}
