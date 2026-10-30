<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Aviso de que algo mudou na inbox. Carrega só ids e tipo: o texto nunca viaja pelo websocket,
 * a tela busca no banco já filtrando pelo que o usuário pode ver.
 */
abstract class InboxEvent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $ticketId,
        public readonly ?int $messageId = null,
        public readonly ?int $assigneeId = null,
    ) {}

    abstract protected function type(): string;

    /** O aviso de tela é leve e não pode esperar atrás do download de uma mídia. */
    public function broadcastQueue(): string
    {
        return 'realtime';
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return array_filter([
            new PrivateChannel('inbox'),
            $this->assigneeId !== null ? new PrivateChannel('users.'.$this->assigneeId) : null,
        ]);
    }

    public function broadcastAs(): string
    {
        return $this->type();
    }

    /**
     * @return array<string, int|string|null>
     */
    public function broadcastWith(): array
    {
        return ['ticket_id' => $this->ticketId, 'message_id' => $this->messageId, 'type' => $this->type()];
    }
}
