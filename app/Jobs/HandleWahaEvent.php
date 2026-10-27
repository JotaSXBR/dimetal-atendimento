<?php

namespace App\Jobs;

use App\Enums\ChannelStatus;
use App\Models\WahaEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class HandleWahaEvent implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $wahaEventId) {}

    public function handle(): void
    {
        $event = WahaEvent::find($this->wahaEventId);

        if ($event === null || $event->channel === null) {
            return;
        }

        if ($event->event === 'session.status') {
            $this->applyStatus($event);
        }
    }

    private function applyStatus(WahaEvent $event): void
    {
        $raw = $event->payload['payload']['status'] ?? null;

        $status = is_string($raw) ? ChannelStatus::tryFrom($raw) : null;

        if ($status === null) {
            return;
        }

        $channel = $event->channel;

        $channel->status = $status;
        $channel->last_event_at = $event->received_at;
        $channel->save();
    }
}
