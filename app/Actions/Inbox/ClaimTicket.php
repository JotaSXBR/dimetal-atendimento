<?php

namespace App\Actions\Inbox;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ClaimTicket
{
    /**
     * Atômico: dois atendentes assumindo ao mesmo tempo, só um vence.
     */
    public function __invoke(Ticket $ticket, User $user): bool
    {
        return DB::transaction(function () use ($ticket, $user): bool {
            $won = Ticket::query()
                ->whereKey($ticket->id)
                ->whereNull('assigned_user_id')
                ->where('status', '<>', TicketStatus::ENCERRADO->value)
                ->update([
                    'assigned_user_id' => $user->id,
                    'status' => TicketStatus::EM_ATENDIMENTO->value,
                    'updated_at' => now(),
                ]) === 1;

            if ($won) {
                TicketEvent::create(['ticket_id' => $ticket->id, 'type' => 'claimed', 'actor_user_id' => $user->id]);
            }

            $ticket->refresh();

            return $won;
        });
    }
}
