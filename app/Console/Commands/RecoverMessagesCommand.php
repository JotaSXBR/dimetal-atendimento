<?php

namespace App\Console\Commands;

use App\Enums\ChannelStatus;
use App\Jobs\RecoverMessagesJob;
use App\Models\Channel;
use Illuminate\Console\Command;

class RecoverMessagesCommand extends Command
{
    protected $signature = 'app:recover-messages';

    protected $description = 'Recupera, em cada canal conectado, as mensagens das últimas 24 h que o webhook possa ter perdido (idempotente)';

    /** Rede de segurança da detecção de queda: cobre o que ela não vê (por exemplo, o app fora do ar com o canal conectado). */
    public function handle(): int
    {
        Channel::query()
            ->where('active', true)
            ->where('status', ChannelStatus::WORKING->value)
            ->each(fn (Channel $channel) => RecoverMessagesJob::dispatch($channel->id, now()->subDay()->toImmutable(), now()->toImmutable()));

        return self::SUCCESS;
    }
}
