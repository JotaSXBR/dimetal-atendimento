<?php

namespace App\Console\Commands;

use App\Enums\ChannelStatus;
use App\Enums\DeliveryStatus;
use App\Jobs\DrainOutboxJob;
use App\Models\Channel;
use App\Models\Message;
use App\Services\Inbox\DrainOutbox;
use App\Services\Inbox\OutboxRecovery;
use Illuminate\Console\Command;

class DrainOutboxCommand extends Command
{
    protected $signature = 'app:drain-outbox';

    protected $description = 'Falha as mensagens que esperaram a conexão por mais de 24 h, recolhe envios presos e agenda o envio das demais nos canais conectados';

    /** Rede de segurança do webhook `session.status`: roda de minuto em minuto. */
    public function handle(DrainOutbox $drain, OutboxRecovery $recovery): int
    {
        $drain->expire();
        $recovery->recoverStuck();

        Channel::query()
            ->where('status', ChannelStatus::WORKING->value)
            ->whereIn('id', Message::query()->where('delivery_status', DeliveryStatus::QUEUED->value)->select('channel_id'))
            ->each(fn (Channel $channel) => DrainOutboxJob::dispatch($channel->id));

        return self::SUCCESS;
    }
}
