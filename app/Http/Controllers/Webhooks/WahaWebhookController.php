<?php

namespace App\Http\Controllers\Webhooks;

use App\Jobs\HandleClientPresence;
use App\Jobs\HandleWahaEvent;
use App\Models\Channel;
use App\Models\WahaEvent;
use App\Services\Waha\WebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WahaWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! WebhookSignature::valid($request)) {
            return response()->json(['ok' => false], 401);
        }

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?: [];

        $eventId = $body['id'] ?? null;

        if (! is_string($eventId) || $eventId === '') {
            return response()->json(['ok' => true]);
        }

        $session = is_string($body['session'] ?? null) ? $body['session'] : null;

        if (($body['event'] ?? null) === 'presence.update') {
            return $this->presence($session, $body['payload'] ?? null);
        }

        $event = WahaEvent::firstOrCreate(
            ['waha_event_id' => $eventId],
            [
                'channel_id' => $this->channelIdFor($session),
                'event' => is_string($body['event'] ?? null) ? $body['event'] : 'unknown',
                'session' => $session,
                'payload' => $body,
                'received_at' => now(),
            ],
        );

        if ($event->wasRecentlyCreated) {
            HandleWahaEvent::dispatch($event->id);
        }

        return response()->json(['ok' => true]);
    }

    /** Presença é efêmera e vem em rajada: nunca vira linha em `waha_events`; o job normaliza e transmite. */
    private function presence(?string $session, mixed $payload): JsonResponse
    {
        $channelId = $this->channelIdFor($session);

        if ($channelId !== null && is_array($payload)) {
            HandleClientPresence::dispatch($channelId, $payload);
        }

        return response()->json(['ok' => true]);
    }

    private function channelIdFor(?string $session): ?int
    {
        if ($session === null) {
            return null;
        }

        $id = Channel::query()->where('waha_session', $session)->value('id');

        return is_int($id) ? $id : null;
    }
}
