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
        private readonly int $open = 0,
        private readonly ?int $limit = null,
        /** @var array<string, int> */
        private readonly array $ignored = [],
    ) {}

    public function toArray(object $notifiable): array
    {
        return array_filter([
            'kind' => 'recovery',
            'level' => 'info',
            'state' => $this->state,
            'channel_id' => $this->channel->id,
            'channel_name' => $this->channel->name,
            'messages' => $this->messages,
            'conversations' => $this->conversations,
            'open' => $this->state === 'imported' ? $this->open : null,
            'limit' => $this->state === 'imported' ? $this->limit : null,
            'ignored' => $this->state === 'imported' && $this->ignored !== [] ? $this->ignored : null,
        ], fn (mixed $value): bool => $value !== null);
    }
}
