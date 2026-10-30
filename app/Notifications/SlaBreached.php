<?php

namespace App\Notifications;

use App\Models\Ticket;

final class SlaBreached extends AlertNotification
{
    public function __construct(private readonly Ticket $ticket) {}

    public function toArray(object $notifiable): array
    {
        return ['kind' => 'sla_breached', 'level' => 'grave', 'ticket_id' => $this->ticket->id];
    }
}
