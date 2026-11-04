<?php

namespace App\Services\Inbox;

use App\Enums\MessageOrigin;
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

    /** Teto do "digitando" do bot: a fila `outbound` tem um processo só, e um bot lento atrasaria a resposta humana. */
    private const BOT_TYPING_CAP_MS = 3000;

    /** O visto do bot é por rodada (mensagens seguidas do mesmo ticket); este é o tamanho da rodada. */
    private const BOT_ROUND_SECONDS = 30;

    /** Quanto tempo a marca "atendente ativo" vale sem ser renovada (o navegador renova a cada poucos segundos). */
    private const HUMAN_ACTIVE_SECONDS = 10;

    /** Um WAHA lento não pode segurar a fila por causa de um visto. */
    private const BOT_SEEN_TIMEOUT_SECONDS = 3;

    /** `$typing` falso (áudio): o cliente já viu "gravando…", então "digitando…" antes da voz seria incoerente. */
    public function before(WahaClient $client, Ticket $ticket, Contact $contact, string $text, bool $typing = true, MessageOrigin $origin = MessageOrigin::PAINEL): void
    {
        $chatId = $contact->wa_id ?? (string) $contact->lid;
        $bot = $origin === MessageOrigin::BOT;
        $channel = $ticket->channel()->firstOrFail();

        // O WAHA só marca `online` sozinho se `WAHA_PRESENCE_AUTO_ONLINE` estiver ligado (aqui não está).
        $this->attempt('online', fn () => $client->setPresence('online'));

        if (! $bot || Cache::add('presence-seen:'.$ticket->id, 1, self::BOT_ROUND_SECONDS)) {
            $this->attempt('visto', fn () => $this->markSeen($client, $ticket, $chatId, $bot ? self::BOT_SEEN_TIMEOUT_SECONDS : null));
        }

        // Atendente digitando ou gravando nesta conversa: o `paused` do bot apagaria o indicador dele.
        $humanActive = $this->isHumanActive($channel, $chatId);

        if (! $typing || ($bot && $humanActive)) {
            return;
        }

        // Quem digitou de verdade já mostrou o "digitando…" pelo navegador: sem espera artificial, só encerra o indicador.
        if ($humanActive) {
            $this->attempt('parou de digitar', fn () => $client->stopTyping($chatId));

            return;
        }

        $this->attempt('digitando', function () use ($client, $chatId, $text, $bot): void {
            $client->startTyping($chatId);
            Sleep::for($bot ? TypingDelay::milliseconds($text, self::BOT_TYPING_CAP_MS) : TypingDelay::milliseconds($text))->milliseconds();
            $client->stopTyping($chatId);
        });
    }

    /** O atendente viu a conversa: o cliente recebe o "visto" sem precisar de uma resposta. */
    public function seen(Ticket $ticket): void
    {
        $this->attempt('visto', function () use ($ticket): void {
            $contact = $ticket->contact()->firstOrFail();

            $this->markSeen(new WahaClient($ticket->channel()->firstOrFail()), $ticket, $contact->wa_id ?? (string) $contact->lid);
        });
    }

    /** "Gravando áudio…" para o cliente enquanto o atendente grava; melhor esforço, como o resto da presença. */
    public function recording(WahaClient $client, string $chatId, bool $on): void
    {
        $this->attempt($on ? 'gravando' : 'parou de gravar', fn () => $client->setPresence($on ? 'recording' : 'paused', $chatId));
    }

    /** "Digitando…" para o cliente enquanto o atendente escreve no campo; `online` antes, como na sequência do WAHA. */
    public function typing(WahaClient $client, string $chatId, bool $on): void
    {
        $this->attempt($on ? 'digitando (campo)' : 'parou de digitar (campo)', function () use ($client, $chatId, $on): void {
            if ($on) {
                $client->setPresence('online');
            }

            $client->setPresence($on ? 'typing' : 'paused', $chatId);
        });
    }

    /**
     * O atendente está digitando ou gravando nesta conversa: o bot não mexe na presença dela e o `offline` do canal espera.
     * Agendar de novo troca o token, então o `offline` que um envio do bot deixou pendente perde a vez.
     */
    public function humanActive(Channel $channel, string $chatId): void
    {
        $this->markHuman($channel, $chatId);
        $this->scheduleOffline($channel);
    }

    public function isHumanActive(Channel $channel, ?string $chatId = null): bool
    {
        return Cache::has($this->humanKey($channel->id, $chatId));
    }

    private function markHuman(Channel $channel, string $chatId): void
    {
        $until = now()->addSeconds(self::HUMAN_ACTIVE_SECONDS);

        Cache::put($this->humanKey($channel->id, $chatId), 1, $until);
        Cache::put($this->humanKey($channel->id), 1, $until);
    }

    private function humanKey(int $channelId, ?string $chatId = null): string
    {
        return "presence-human:{$channelId}".($chatId === null ? '' : ":{$chatId}");
    }

    public function scheduleOffline(Channel $channel): void
    {
        $token = Str::random(16);

        Cache::put($this->key($channel->id), $token, now()->addMinutes(5));
        GoOfflineJob::dispatch($channel->id, $token)->delay(now()->addSeconds(self::OFFLINE_AFTER_SECONDS));
    }

    public function goOffline(Channel $channel, string $token): void
    {
        if (Cache::get($this->key($channel->id)) !== $token) {
            return;
        }

        if ($this->isHumanActive($channel)) {
            $this->scheduleOffline($channel);

            return;
        }

        $this->attempt('offline', fn () => (new WahaClient($channel))->setPresence('offline'));
    }

    private function markSeen(WahaClient $client, Ticket $ticket, string $chatId, ?int $timeoutSeconds = null): void
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
            $client->sendSeen($chatId, $ids, $timeoutSeconds);
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
