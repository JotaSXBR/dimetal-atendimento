<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Services\Alerts\AlertDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Roda 60 s depois de o canal sair de WORKING e anuncia a queda se ela ainda existe. Uma oscilação que já voltou, ou uma
 * segunda queda que ainda não completou a tolerância, não anuncia nada.
 */
class ConfirmChannelDownJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $channelId)
    {
        $this->onQueue('realtime');
    }

    public function handle(AlertDispatcher $alerts): void
    {
        $channel = Channel::query()->find($this->channelId);

        if ($channel === null || ! $channel->active || $channel->isConnected()) {
            return;
        }

        // `down_since` é o início da queda de agora: se for mais novo que a tolerância, este job é de uma queda anterior.
        if ($channel->down_since === null || $channel->down_since->gt(now()->subSeconds(AlertDispatcher::GRACE_SECONDS))) {
            return;
        }

        $alerts->confirmDown($channel);
    }
}
