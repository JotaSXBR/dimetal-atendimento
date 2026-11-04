<?php

namespace App\Services\Inbox;

use App\Events\ClientPresenceChanged;
use App\Models\Channel;
use App\Models\Ticket;
use App\Services\Waha\WahaChat;
use Illuminate\Support\Facades\Cache;

/**
 * Presença do cliente (`presence.update` do WAHA): o estado atual vive no cache com TTL, o aviso vai pelo Reverb e nada
 * é gravado no banco. Quem não compartilha presença simplesmente não aparece.
 */
class ClientPresence
{
    private const STATES = ['typing' => 'digitando', 'recording' => 'gravando', 'paused' => 'parou', 'online' => 'parou', 'offline' => 'parou'];

    /**
     * @param  array<mixed>  $payload
     * @return array{chat: string, state: 'digitando'|'gravando'|'parou'}|null
     */
    public function fromPayload(array $payload): ?array
    {
        $chat = $payload['id'] ?? null;
        $presences = $payload['presences'] ?? null;

        if (! is_string($chat) || $chat === '' || ! is_array($presences)) {
            return null;
        }

        $own = collect($presences)->first(fn ($item): bool => is_array($item) && ($item['participant'] ?? $chat) === $chat);
        $raw = is_array($own) ? ($own['lastKnownPresence'] ?? null) : null;
        $state = is_string($raw) ? (self::STATES[$raw] ?? null) : null;

        return $state === null ? null : ['chat' => $chat, 'state' => $state];
    }

    /** @param  array<mixed>  $payload */
    public function apply(Channel $channel, array $payload): void
    {
        $parsed = $this->fromPayload($payload);

        if ($parsed === null || WahaChat::isIgnored($parsed['chat'])) {
            return;
        }

        $ticket = Ticket::query()
            ->open()
            ->where('channel_id', $channel->id)
            ->whereHas('contact', fn ($q) => $q->where('wa_id', $parsed['chat'])->orWhere('lid', $parsed['chat']))
            ->first();

        if ($ticket === null) {
            return;
        }

        if ($parsed['state'] === 'parou') {
            $this->clear($ticket->id);

            return;
        }

        Cache::put($this->key($ticket->id), $parsed['state'], now()->addSeconds(config()->integer('presence.state_ttl_seconds')));
        ClientPresenceChanged::dispatch($ticket->id, $parsed['state']);
    }

    /** O cliente parou (ou a mensagem dele chegou): some o aviso. Sem estado guardado, não há o que apagar nem o que transmitir. */
    public function clear(int $ticketId): void
    {
        if (Cache::pull($this->key($ticketId)) !== null) {
            ClientPresenceChanged::dispatch($ticketId, 'parou');
        }
    }

    /**
     * @param  array<int, int>  $ticketIds
     * @return array<int, string>
     */
    public function states(array $ticketIds): array
    {
        $states = [];

        foreach ($ticketIds as $id) {
            $state = Cache::get($this->key($id));

            if ($state === 'digitando' || $state === 'gravando') {
                $states[$id] = $state;
            }
        }

        return $states;
    }

    private function key(int $ticketId): string
    {
        return 'client-presence:'.$ticketId;
    }
}
