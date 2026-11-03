<?php

namespace App\Notifications;

use App\Models\Channel;

/** Resumo, no sino e sem som, do que uma recuperação (`recovered`) ou importação (`imported`) trouxe. */
final class MessagesRecovered extends AlertNotification
{
    public function __construct(
        private readonly Channel $channel,
        private readonly int $messages,
        private readonly int $conversations,
        private readonly string $state = 'recovered',
    ) {}

    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'recovery',
            'level' => 'info',
            'state' => $this->state,
            'channel_id' => $this->channel->id,
            'channel_name' => $this->channel->name,
            'messages' => $this->messages,
            'conversations' => $this->conversations,
        ];
    }
}
