<?php

namespace App\Notifications;

use App\Models\Channel;

final class ChannelUp extends AlertNotification
{
    public function __construct(private readonly Channel $channel) {}

    public function toArray(object $notifiable): array
    {
        return ['kind' => 'connection', 'level' => 'info', 'state' => 'up', 'channel_id' => $this->channel->id, 'channel_name' => $this->channel->name];
    }
}
