<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Notifications\MessagesRecovered;
use App\Services\Alerts\AlertRecipients;
use App\Services\Inbox\ImportHistory;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Notification;
use Throwable;

/** A importação inicial do histórico de um canal: roda uma vez, na primeira conexão, na fila `history`. */
class ImportHistoryJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 840;

    public function __construct(public int $channelId)
    {
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

    public function handle(ImportHistory $import): void
    {
        $channel = Channel::query()->find($this->channelId);

        if ($channel === null || ! $channel->active || ! $channel->isConnected()) {
            return;
        }

        $result = $import($channel);

        Notification::send(app(AlertRecipients::class)->everyone(), new MessagesRecovered($channel, $result->messages, $result->conversations, 'imported', $import->open));
    }

    /** A importação não terminou: libera a próxima conexão para tentar de novo. */
    public function failed(Throwable $e): void
    {
        Channel::query()->whereKey($this->channelId)->update(['backfill_last_run_at' => null]);
    }
}
