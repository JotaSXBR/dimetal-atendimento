<?php

namespace App\Events;

use App\Models\Ticket;

class TicketUpdated extends InboxEvent
{
    public function __construct(Ticket $ticket)
    {
        parent::__construct($ticket->id, null, $ticket->assigned_user_id);
    }

    protected function type(): string
    {
        return 'ticket.updated';
    }
}
