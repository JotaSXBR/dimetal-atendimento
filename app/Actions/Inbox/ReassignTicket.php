<?php

namespace App\Actions\Inbox;

use App\Enums\TicketStatus;
use App\Events\TicketUpdated;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ReassignTicket
{
    /**
     * Supervisor/admin escolhem quem fica com o ticket. Falso quando o destino não pode atender (inativo ou de
     * outro setor), o ticket está encerrado ou já está com ele.
     */
    public function __invoke(Ticket $ticket, User $actor, User $to): bool
    {
        if (! $to->active || ! $to->canHandleSector($ticket->sector_id)) {
            return false;
        }

        return DB::transaction(function () use ($ticket, $actor, $to): bool {
            $changed = Ticket::query()
                ->whereKey($ticket->id)
                ->where('status', '<>', TicketStatus::ENCERRADO->value)
                ->where(fn ($q) => $q->whereNull('assigned_user_id')->orWhere('assigned_user_id', '<>', $to->id))
                ->update([
                    'assigned_user_id' => $to->id,
                    'status' => TicketStatus::EM_ATENDIMENTO->value,
                    'updated_at' => now(),
                ]) === 1;

            if ($changed) {
                TicketEvent::create([
                    'ticket_id' => $ticket->id,
                    'type' => 'reassigned',
                    'actor_user_id' => $actor->id,
                    'payload' => array_filter(
                        ['from_user' => $ticket->assigned_user_id, 'to_user' => $to->id],
                        fn ($value) => $value !== null,
                    ),
                ]);
            }

            $ticket->refresh();

            if ($changed) {
                TicketUpdated::dispatch($ticket);
            }

            return $changed;
        });
    }
}
