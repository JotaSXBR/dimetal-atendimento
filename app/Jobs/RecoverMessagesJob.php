<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Notifications\MessagesRecovered;
use App\Services\Alerts\AlertRecipients;
use App\Services\Inbox\RecoverMessages;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Recupera as mensagens de uma janela de um canal conectado. Roda na fila `history`, longe da ingestão ao vivo, e só uma
 * por canal de cada vez (a recuperação automática, a manual, a varredura diária e a importação não se atropelam).
 */
class RecoverMessagesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 840;

    /** O lock de unicidade expira sozinho: um worker morto no meio do job (deploy) não pode travar o canal para sempre. */
    public int $uniqueFor = 900;

    public function __construct(
        public int $channelId,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public bool $notifyAlways = false,
        public ?CarbonImmutable $downSince = null,
    ) {
        $this->onQueue('history');
    }

    /** Reenfileira por até 1 h se o canal estiver fora de WORKING naquele instante (oscilação); o limite é de tempo. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHour();
    }

    public function uniqueId(): string
    {
        return (string) $this->channelId;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('history:'.$this->channelId))->releaseAfter(60)->expireAfter(900)];
    }

    public function handle(RecoverMessages $recover): void
    {
        $channel = Channel::query()->find($this->channelId);

        if ($channel === null || ! $channel->active) {
            return;
        }

        if (! $channel->isConnected()) {
            $this->release(60);

            return;
        }

        // O aparelho trocou durante a queda: o histórico que o WAHA devolve agora é de outro número.
        if ($this->downSince !== null && $channel->phone_changed_at !== null && $channel->phone_changed_at->gte($this->downSince)) {
            Log::info('Recuperação cancelada: o número do canal mudou durante a queda', ['canal' => $channel->id]);

            return;
        }

        $result = $recover($channel, $this->from, $this->to);

        if ($result->messages > 0 || $this->notifyAlways) {
            Notification::send(app(AlertRecipients::class)->everyone(), new MessagesRecovered($channel, $result->messages, $result->conversations));
        }
    }
}
