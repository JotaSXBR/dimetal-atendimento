<?php

namespace App\Jobs;

use App\Enums\ChannelStatus;
use App\Models\WahaEvent;
use App\Services\Inbox\ApplyAck;
use App\Services\Inbox\ApplyMessageChange;
use App\Services\Inbox\IngestMessage;
use App\Services\Waha\ChannelPhone;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class HandleWahaEvent implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /** O WAHA não reenvia um webhook já aceito: se o job falhar de vez, a mensagem se perde. Daí as tentativas. */
    public int $tries = 6;

    /** @var array<int, int> */
    public array $backoff = [2, 5, 10, 30];

    private const ACK_MAX_ATTEMPTS = 6;

    private const ACK_RETRY_SECONDS = 5;

    public function __construct(public int $wahaEventId)
    {
        $this->onQueue('inbound');
    }

    public function handle(): void
    {
        $event = WahaEvent::find($this->wahaEventId);

        if ($event === null || $event->channel === null) {
            return;
        }

        match ($event->event) {
            'session.status' => $this->applyStatus($event),
            'message', 'message.any', 'message.waiting' => app(IngestMessage::class)($event),
            'message.ack' => $this->retryUnlessDone(app(ApplyAck::class)($event), $event, 'Ack descartado: mensagem nunca apareceu'),
            'message.reaction' => $this->retryUnlessDone(app(ApplyMessageChange::class)->reaction($event), $event, 'Reação descartada: mensagem nunca apareceu'),
            'message.edited' => $this->retryUnlessDone(app(ApplyMessageChange::class)->edited($event), $event, 'Edição descartada: mensagem nunca apareceu'),
            'message.revoked' => $this->retryUnlessDone(app(ApplyMessageChange::class)->revoked($event), $event, 'Apagamento descartado: mensagem nunca apareceu'),
            default => null,
        };
    }

    /** Ack, reação, edição e apagamento podem chegar antes da mensagem ser gravada: tenta de novo e, esgotadas as tentativas, descarta. */
    private function retryUnlessDone(bool $done, WahaEvent $event, string $discardMessage): void
    {
        if ($done) {
            return;
        }

        if ($this->attempts() >= self::ACK_MAX_ATTEMPTS) {
            Log::warning($discardMessage, ['event' => $event->waha_event_id]);

            return;
        }

        $this->release(self::ACK_RETRY_SECONDS);
    }

    private function applyStatus(WahaEvent $event): void
    {
        $raw = data_get($event->payload, 'payload.status');

        $status = is_string($raw) ? ChannelStatus::tryFrom($raw) : null;

        if ($status === null) {
            return;
        }

        $channel = $event->channel;

        if ($channel === null) {
            return;
        }

        $channel->status = $status;
        $channel->last_event_at = $event->received_at;
        $channel->save();

        $me = data_get($event->payload, 'me.id');
        app(ChannelPhone::class)->sync($channel, is_string($me) ? $me : null);
    }
}
