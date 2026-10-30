<?php

namespace App\Observers;

use App\Enums\ChannelStatus;
use App\Jobs\DrainOutboxJob;
use App\Models\Channel;
use App\Services\Alerts\AlertDispatcher;

/**
 * Um único lugar decide quando a caixa de saída esvazia: sempre que o canal passa a WORKING, venha a mudança do webhook
 * `session.status` ou da tela Canais (que grava o status sozinha por polling e fazia o webhook chegar tarde demais).
 */
class ChannelObserver
{
    public function updated(Channel $channel): void
    {
        if (! $channel->wasChanged('status')) {
            return;
        }

        if ($channel->status === ChannelStatus::WORKING) {
            DrainOutboxJob::dispatch($channel->id)->afterCommit();
        }

        $raw = $channel->getRawOriginal('status');
        $from = is_string($raw) ? ChannelStatus::tryFrom($raw) : null;

        if ($from !== null) {
            app(AlertDispatcher::class)->channelChanged($channel, $from);
        }
    }
}
