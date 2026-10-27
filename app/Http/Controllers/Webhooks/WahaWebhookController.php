<?php

namespace App\Http\Controllers\Webhooks;

use App\Jobs\HandleWahaEvent;
use App\Models\Channel;
use App\Models\WahaEvent;
use App\Settings\WahaSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WahaWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        $raw = $request->getContent();
        $secret = WahaSettings::webhookSecret();

        if (! $this->signatureIsValid($request, $raw, $secret)) {
            return response()->json(['ok' => false], 401);
        }

        /** @var array<string, mixed> $body */
        $body = json_decode($raw, true) ?: [];

        $eventId = $body['id'] ?? null;

        if (! is_string($eventId) || $eventId === '') {
            return response()->json(['ok' => true]);
        }

        $session = is_string($body['session'] ?? null) ? $body['session'] : null;

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

    private function signatureIsValid(Request $request, string $raw, string $secret): bool
    {
        if ($secret === '') {
            return false;
        }

        $signature = (string) $request->header('X-Webhook-Hmac', '');
        $algorithm = strtolower((string) $request->header('X-Webhook-Hmac-Algorithm', 'sha512'));

        if ($signature === '' || $algorithm !== 'sha512') {
            return false;
        }

        return hash_equals(hash_hmac('sha512', $raw, $secret), strtolower($signature));
    }

    private function channelIdFor(?string $session): ?int
    {
        if ($session === null) {
            return null;
        }

        return Channel::query()->where('waha_session', $session)->value('id');
    }
}
