<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** O estado de um canal do WhatsApp mudou: a faixa de todas as telas se atualiza. Só ids e o nome do estado. */
class ChannelStatusChanged implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly int $channelId, public readonly string $status) {}

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
        return 'channel.status';
    }

    /** @return array<string, int|string> */
    public function broadcastWith(): array
    {
        return ['channel_id' => $this->channelId, 'status' => $this->status];
    }
}
