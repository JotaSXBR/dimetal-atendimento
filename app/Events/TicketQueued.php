<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Ticket novo na fila: toca o som "fila" só de quem vê aquela fila. Não é guardado no sino (o volume lotaria a lista). */
class TicketQueued implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param  array<int, int>  $userIds */
    public function __construct(public readonly int $ticketId, public readonly array $userIds) {}

    public function broadcastQueue(): string
    {
        return 'realtime';
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return array_map(fn (int $id): PrivateChannel => new PrivateChannel('users.'.$id), $this->userIds);
    }

    public function broadcastAs(): string
    {
        return 'alert.queue';
    }

    /** @return array<string, int|string> */
    public function broadcastWith(): array
    {
        return ['kind' => 'queue', 'ticket_id' => $this->ticketId];
    }
}
