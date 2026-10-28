<?php

namespace App\Jobs;

use App\Enums\ChannelStatus;
use App\Models\WahaEvent;
use App\Services\Inbox\ApplyAck;
use App\Services\Inbox\IngestMessage;
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

    public function __construct(public int $wahaEventId) {}

    public function handle(): void
    {
        $event = WahaEvent::find($this->wahaEventId);

        if ($event === null || $event->channel === null) {
            return;
        }

        match ($event->event) {
            'session.status' => $this->applyStatus($event),
            'message', 'message.any' => app(IngestMessage::class)($event),
            'message.ack' => $this->applyAck($event),
            default => null,
        };
    }

    /** O ack pode chegar antes da mensagem ser gravada: tenta de novo e, esgotadas as tentativas, descarta. */
    private function applyAck(WahaEvent $event): void
    {
        if (app(ApplyAck::class)($event)) {
            return;
        }

        if ($this->attempts() >= self::ACK_MAX_ATTEMPTS) {
            Log::warning('Ack descartado: mensagem nunca apareceu', ['event' => $event->waha_event_id]);

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
    }
}
