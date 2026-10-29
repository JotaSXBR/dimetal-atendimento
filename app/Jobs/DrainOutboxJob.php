<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Services\Inbox\DrainOutbox;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Envia as mensagens que ficaram aguardando a conexão do canal. Fila `outbound`: um processo só, na mesma ordem dos envios.
 */
class DrainOutboxJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    // Menos que o `--timeout=120` do worker: dez envios com presença cabem, e o que sobrar vira outra rodada.
    public int $timeout = 110;

    public function __construct(public readonly int $channelId)
    {
        $this->onQueue('outbound');
    }

    public function handle(DrainOutbox $drain): void
    {
        $channel = Channel::find($this->channelId);

        if ($channel !== null && $drain($channel)) {
            self::dispatch($this->channelId);
        }
    }
}
