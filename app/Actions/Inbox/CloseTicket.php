<?php

namespace App\Actions\Inbox;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CloseTicket
{
    /**
     * Idempotente: só o fechamento que de fato mudou o status grava evento.
     */
    public function __invoke(Ticket $ticket, User $user): void
    {
        DB::transaction(function () use ($ticket, $user): void {
            $closed = Ticket::query()
                ->whereKey($ticket->id)
                ->where('status', '<>', TicketStatus::ENCERRADO->value)
                ->update([
                    'status' => TicketStatus::ENCERRADO->value,
                    'closed_at' => now(),
                    'updated_at' => now(),
                ]) === 1;

            if ($closed) {
                TicketEvent::create(['ticket_id' => $ticket->id, 'type' => 'closed', 'actor_user_id' => $user->id]);
            }
        });

        $ticket->refresh();
    }
}
