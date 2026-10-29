<?php

namespace App\Services\Inbox;

use App\Jobs\GoOfflineJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Services\Waha\WahaClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

/**
 * Presença humana ao responder: visto, digitando e, depois de um tempo sem envios, offline.
 * Tudo é melhor esforço: uma falha aqui vai para o log e nunca derruba o envio.
 */
class Presence
{
    /** Quanto tempo sem envios antes de voltar a "offline". */
    private const OFFLINE_AFTER_SECONDS = 45;

    private const LAST_SEEN_IDS = 20;

    public function before(WahaClient $client, Ticket $ticket, Contact $contact, string $text): void
    {
        $chatId = $contact->wa_id ?? (string) $contact->lid;

        $this->attempt('visto', fn () => $this->markSeen($client, $ticket, $chatId));
        $this->attempt('digitando', function () use ($client, $chatId, $text): void {
            $client->startTyping($chatId);
            Sleep::for(TypingDelay::milliseconds($text))->milliseconds();
            $client->stopTyping($chatId);
        });
    }

    /** "Gravando áudio…" para o cliente enquanto o atendente grava; melhor esforço, como o resto da presença. */
    public function recording(WahaClient $client, string $chatId, bool $on): void
    {
        $this->attempt($on ? 'gravando' : 'parou de gravar', fn () => $client->setPresence($on ? 'recording' : 'paused', $chatId));
    }

    public function scheduleOffline(Channel $channel): void
    {
        $token = Str::random(16);

        Cache::put($this->key($channel->id), $token, now()->addMinutes(5));
        GoOfflineJob::dispatch($channel->id, $token)->delay(now()->addSeconds(self::OFFLINE_AFTER_SECONDS));
    }

    public function goOffline(Channel $channel, string $token): void
    {
        if (Cache::get($this->key($channel->id)) === $token) {
            $this->attempt('offline', fn () => (new WahaClient($channel))->setPresence('offline'));
        }
    }

    private function markSeen(WahaClient $client, Ticket $ticket, string $chatId): void
    {
        $received = Message::query()
            ->where('ticket_id', $ticket->id)
            ->where('direction', 'in')
            ->whereNotNull('wa_message_id')
            ->latest('sent_at')
            ->limit(self::LAST_SEEN_IDS)
            ->get();

        $ids = [];

        foreach ($received as $message) {
            if ($message->wa_message_id !== null) {
                // O WAHA só aceita o id completo `false_{chat}_{id}` (mensagem do cliente); só o final dá 500.
                $ids[] = 'false_'.$chatId.'_'.$message->wa_message_id;
            }
        }

        if ($ids !== []) {
            $client->sendSeen($chatId, $ids);
        }
    }

    private function attempt(string $step, \Closure $action): void
    {
        try {
            $action();
        } catch (Throwable $e) {
            Log::warning("Presença ({$step}) falhou e foi ignorada", ['erro' => $e->getMessage()]);
        }
    }

    private function key(int $channelId): string
    {
        return "presence-offline:{$channelId}";
    }
}
