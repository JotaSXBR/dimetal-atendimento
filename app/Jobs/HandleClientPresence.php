<?php

namespace App\Jobs;

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

    public function handle(): void
    {
        // Corpo real na tarefa 4; por ora só o registro do payload bruto, que a medição no beta lê.
        Log::debug('presence.update bruto', ['channel_id' => $this->channelId, 'payload' => $this->payload]);
    }
}
