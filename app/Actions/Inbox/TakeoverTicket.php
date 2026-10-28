<?php

namespace App\Actions\Inbox;

use App\Enums\TicketStatus;
use App\Events\TicketUpdated;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TakeoverTicket
{
    /**
     * Tira o ticket do responsável que o usuário viu na tela. A troca é condicional a esse responsável: se o
     * ticket mudou de mão no meio do caminho (outro colega tomou antes), quem chegou depois perde e recebe falso.
     */
    public function __invoke(Ticket $ticket, User $user, int $seenAssignee): bool
    {
        return DB::transaction(function () use ($ticket, $user, $seenAssignee): bool {
            $taken = Ticket::query()
                ->whereKey($ticket->id)
                ->where('status', TicketStatus::EM_ATENDIMENTO->value)
                ->where('assigned_user_id', $seenAssignee)
                ->update(['assigned_user_id' => $user->id, 'updated_at' => now()]) === 1;

            if ($taken) {
                TicketEvent::create([
                    'ticket_id' => $ticket->id,
                    'type' => 'taken_over',
                    'actor_user_id' => $user->id,
                    'payload' => ['from_user' => $seenAssignee, 'to_user' => $user->id],
                ]);
            }

            $ticket->refresh();

            if ($taken) {
                TicketUpdated::dispatch($ticket);
            }

            return $taken;
        });
    }
}
