<?php

namespace App\Events;

use App\Models\Message;

class MessageCreated extends InboxEvent
{
    private readonly string $direction;

    public function __construct(Message $message)
    {
        parent::__construct($message->ticket_id, $message->id, $message->ticket?->assigned_user_id);

        $this->direction = $message->direction->value;
    }

    /**
     * A direção deixa o módulo de som tocar só para mensagem de cliente. O texto nunca viaja.
     *
     * @return array<string, int|string|null>
     */
    public function broadcastWith(): array
    {
        return [...parent::broadcastWith(), 'direction' => $this->direction];
    }

    protected function type(): string
    {
        return 'message.created';
    }
}
