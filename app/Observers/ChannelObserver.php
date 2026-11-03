<?php

namespace App\Observers;

use App\Enums\ChannelStatus;
use App\Jobs\DrainOutboxJob;
use App\Jobs\RecoverMessagesJob;
use App\Models\Channel;
use App\Services\Alerts\AlertDispatcher;
use Carbon\CarbonImmutable;

/**
 * Um único lugar decide quando a caixa de saída esvazia: sempre que o canal passa a WORKING, venha a mudança do webhook
 * `session.status` ou da tela Canais (que grava o status sozinha por polling e fazia o webhook chegar tarde demais).
 */
class ChannelObserver
{
    /** Queda mais curta que isto é reinício: nas medições, nada se perde nela. */
    private const MIN_OUTAGE_MINUTES = 5;

    /** Quanto antes da queda a janela começa (o último evento pode ter chegado antes de o status mudar). */
    private const MARGIN_MINUTES = 10;

    /** Espera a caixa de saída esvaziar, para o eco das mensagens do painel não virar duplicata. */
    private const DELAY_SECONDS = 60;

    public function updated(Channel $channel): void
    {
        if (! $channel->wasChanged('status')) {
            return;
        }

        $raw = $channel->getRawOriginal('status');
        $from = is_string($raw) ? ChannelStatus::tryFrom($raw) : null;

        $this->trackOutage($channel, $from);

        if ($channel->status === ChannelStatus::WORKING) {
            DrainOutboxJob::dispatch($channel->id)->afterCommit();
        }

        if ($from !== null) {
            app(AlertDispatcher::class)->channelChanged($channel, $from);
        }
    }

    /**
     * Marca desde quando o canal está fora de WORKING e, na volta de uma queda de 5 min ou mais, agenda a recuperação do
     * período. Grava por consulta (não pelo modelo) para não mexer no estado "original" que o resto do observer lê, e
     * condicionado ao valor lido: poll da tela e webhook voltam ao mesmo tempo e só um deles dispara.
     */
    private function trackOutage(Channel $channel, ?ChannelStatus $from): void
    {
        if ($channel->status !== ChannelStatus::WORKING) {
            if ($from === ChannelStatus::WORKING && $channel->active) {
                Channel::query()->whereKey($channel->id)->whereNull('down_since')->update(['down_since' => now()]);
            }

            return;
        }

        $stored = Channel::query()->whereKey($channel->id)->value('down_since');

        if ($stored === null || Channel::query()->whereKey($channel->id)->where('down_since', $stored)->update(['down_since' => null]) !== 1) {
            return;
        }

        $since = CarbonImmutable::parse($stored);

        if ($channel->active && $since->diffInMinutes(now(), true) >= self::MIN_OUTAGE_MINUTES) {
            RecoverMessagesJob::dispatch($channel->id, $since->subMinutes(self::MARGIN_MINUTES), now()->toImmutable(), false, $since)
                ->delay(self::DELAY_SECONDS);
        }
    }
}
