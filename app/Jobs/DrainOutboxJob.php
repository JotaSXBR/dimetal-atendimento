<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Services\Inbox\DrainOutbox;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Envia as mensagens que ficaram aguardando a conexão do canal. Fila `outbound`: um processo só, na mesma ordem dos envios.
 *
 * Único por canal só até começar a rodar: disparos repetidos (webhook, tela Canais, rotina) viram um, mas o job pode se
 * reagendar de dentro de `handle` (o bloqueio já foi solto).
 */
class DrainOutboxJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    /** Quantas vezes o job se reagenda quando a sessão já consta WORKING mas o WAHA ainda recusa (sincronização). */
    public const MAX_RETRIES = 6;

    public const RETRY_SECONDS = 10;

    public int $tries = 1;

    // Menos que o `--timeout=120` do worker: dez envios com presença cabem, e o que sobrar vira outra rodada.
    public int $timeout = 110;

    public function __construct(public readonly int $channelId)
    {
        $this->onQueue('outbound');
    }

    public function uniqueId(): string
    {
        return (string) $this->channelId;
    }

    public function handle(DrainOutbox $drain): void
    {
        $channel = Channel::find($this->channelId);

        if ($channel === null) {
            return;
        }

        if ($drain($channel)) {
            self::dispatch($this->channelId);

            return;
        }

        $key = 'outbox:retries:'.$this->channelId;

        if (! $drain->stuckWhileConnected($channel)) {
            Cache::forget($key);

            return;
        }

        // O canal está WORKING mas o WAHA ainda responde "sessão fora do ar" (sincronização logo após reconectar).
        Cache::add($key, 0, now()->addMinutes(5));

        if (Cache::increment($key) <= self::MAX_RETRIES) {
            self::dispatch($this->channelId)->delay(now()->addSeconds(self::RETRY_SECONDS));
        }
    }
}
