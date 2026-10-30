<?php

namespace App\Notifications;

use App\Models\Ticket;

final class TicketAssigned extends AlertNotification
{
    public function __construct(private readonly Ticket $ticket) {}

    public function toArray(object $notifiable): array
    {
        return ['kind' => 'assigned', 'level' => 'comum', 'ticket_id' => $this->ticket->id];
    }
}
