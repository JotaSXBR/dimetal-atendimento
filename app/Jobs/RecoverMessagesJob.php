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

    public int $tries = 1;

    public int $timeout = 840;

    public function __construct(
        public int $channelId,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public bool $notifyAlways = false,
        public ?CarbonImmutable $downSince = null,
    ) {
        $this->onQueue('history');
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

        if ($channel === null || ! $channel->active || ! $channel->isConnected()) {
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
