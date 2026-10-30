<?php

namespace App\Actions\Inbox;

use App\Enums\ClosedReason;
use App\Enums\CloseOutcome;
use App\Enums\TicketStatus;
use App\Events\TicketUpdated;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CloseTicket
{
    /**
     * Idempotente: só o fechamento que de fato mudou o status grava evento.
     */
    public function __invoke(Ticket $ticket, User $user, ClosedReason $reason = ClosedReason::MANUAL, ?CloseOutcome $outcome = null, ?string $summary = null): void
    {
        DB::transaction(function () use ($ticket, $user, $reason, $outcome, $summary): void {
            $closed = Ticket::query()
                ->whereKey($ticket->id)
                ->where('status', '<>', TicketStatus::ENCERRADO->value)
                ->update([
                    'status' => TicketStatus::ENCERRADO->value,
                    'closed_at' => now(),
                    'closed_reason' => $reason->value,
                    'close_outcome' => $outcome?->value,
                    'close_summary' => $summary,
                    'updated_at' => now(),
                ]) === 1;

            if ($closed) {
                TicketEvent::create([
                    'ticket_id' => $ticket->id,
                    'type' => 'closed',
                    'actor_user_id' => $user->id,
                    'payload' => array_filter(['reason' => $reason->value, 'outcome' => $outcome?->value, 'summary' => $summary]),
                ]);
                TicketUpdated::dispatch($ticket->refresh());
            }
        });

        $ticket->refresh();
    }
}
