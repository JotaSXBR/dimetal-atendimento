<?php

namespace App\Notifications;

use App\Models\Channel;

/** O WAHA informou outro número para a mesma sessão (chip trocado): só avisa, não bloqueia nada. Sem telefones no payload. */
final class ChannelNumberChanged extends AlertNotification
{
    public function __construct(private readonly Channel $channel) {}

    public function toArray(object $notifiable): array
    {
        return ['kind' => 'connection', 'level' => 'info', 'state' => 'number_changed', 'channel_id' => $this->channel->id, 'channel_name' => $this->channel->name];
    }
}
