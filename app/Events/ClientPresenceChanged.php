<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** O cliente começou ou parou de digitar/gravar: a lista e a conversa mostram ou apagam o aviso. Só ids e estado, nunca texto. */
class ClientPresenceChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param  'digitando'|'gravando'|'parou'  $state */
    public function __construct(public readonly int $ticketId, public readonly string $state) {}

    public function broadcastQueue(): string
    {
        return 'realtime';
    }

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('inbox')];
    }

    public function broadcastAs(): string
    {
        return 'client.presence';
    }

    /** @return array<string, int|string> */
    public function broadcastWith(): array
    {
        return ['ticket_id' => $this->ticketId, 'state' => $this->state];
    }
}
