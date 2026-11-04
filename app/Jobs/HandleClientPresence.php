<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Services\Inbox\ClientPresence;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/** Presença do cliente (`presence.update`): efêmera, nunca gravada em `waha_events`. Tratada na fila de tempo real. */
class HandleClientPresence implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** @param  array<mixed>  $payload */
    public function __construct(public readonly int $channelId, public readonly array $payload)
    {
        $this->onQueue('realtime');
    }

    public function handle(ClientPresence $presence): void
    {
        // O payload bruto fica no log de debug: é o que a medição no beta lê e o que o parser tolera.
        Log::debug('presence.update bruto', ['channel_id' => $this->channelId, 'payload' => $this->payload]);

        $channel = Channel::query()->find($this->channelId);
        $channel !== null && $presence->apply($channel, $this->payload);
    }
}
