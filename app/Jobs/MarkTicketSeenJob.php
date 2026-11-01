<?php

namespace App\Jobs;

use App\Models\Ticket;
use App\Services\Inbox\Presence;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Manda o "visto" ao cliente quando o atendente abre (ou está com aberta) a conversa; fora da requisição da tela. */
class MarkTicketSeenJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $ticketId) {}

    public function handle(Presence $presence): void
    {
        $ticket = Ticket::query()->find($this->ticketId);

        if ($ticket !== null) {
            $presence->seen($ticket);
        }
    }
}
