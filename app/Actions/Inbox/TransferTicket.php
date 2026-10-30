<?php

namespace App\Actions\Inbox;

use App\Enums\TicketStatus;
use App\Events\TicketUpdated;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Services\Alerts\AlertDispatcher;
use Illuminate\Support\Facades\DB;

class TransferTicket
{
    /**
     * Devolve o ticket à fila do setor de destino, sem responsável. Falso quando nada mudou: setor que não
     * recebe tickets, ticket já encerrado ou já parado no destino sem dono.
     */
    public function __invoke(Ticket $ticket, User $actor, Sector $to, ?string $note = null): bool
    {
        if (! $to->status->receivesTickets()) {
            return false;
        }

        return DB::transaction(function () use ($ticket, $actor, $to, $note): bool {
            $moved = Ticket::query()
                ->whereKey($ticket->id)
                ->where('status', '<>', TicketStatus::ENCERRADO->value)
                ->where(fn ($q) => $q
                    ->whereNull('sector_id')
                    ->orWhere('sector_id', '<>', $to->id)
                    ->orWhereNotNull('assigned_user_id'))
                ->update([
                    'sector_id' => $to->id,
                    'assigned_user_id' => null,
                    'status' => TicketStatus::AGUARDANDO->value,
                    'updated_at' => now(),
                ]) === 1;

            if ($moved) {
                TicketEvent::create([
                    'ticket_id' => $ticket->id,
                    'type' => 'transferred',
                    'actor_user_id' => $actor->id,
                    'payload' => array_filter([
                        'from_sector' => $ticket->sector_id,
                        'to_sector' => $to->id,
                        'from_user' => $ticket->assigned_user_id,
                        'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
                    ], fn ($value) => $value !== null),
                ]);
            }

            $ticket->refresh();

            if ($moved) {
                TicketUpdated::dispatch($ticket);
                app(AlertDispatcher::class)->queued($ticket, $actor->id);
            }

            return $moved;
        });
    }
}
