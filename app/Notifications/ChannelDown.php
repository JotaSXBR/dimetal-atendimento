<?php

namespace App\Notifications;

use App\Models\Channel;

final class ChannelDown extends AlertNotification
{
    public function __construct(private readonly Channel $channel) {}

    public function toArray(object $notifiable): array
    {
        return ['kind' => 'connection', 'level' => 'critico', 'state' => 'down', 'channel_id' => $this->channel->id, 'channel_name' => $this->channel->name];
    }
}
