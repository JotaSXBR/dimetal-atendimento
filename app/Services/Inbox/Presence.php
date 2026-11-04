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

    /** Quanto tempo vale "o bloco Esperar já mostrou o digitando" para a próxima mensagem do bot. */
    private const BOT_TYPED_SECONDS = 15;

    /** Quanto tempo a marca "atendente ativo" vale sem ser renovada (o navegador renova a cada poucos segundos). */
    private const HUMAN_ACTIVE_SECONDS = 10;

    /** Quanto tempo vale "a sessão já está online": o `offline` fecha a janela antes; isto é só a rede de segurança. */
    private const ONLINE_WINDOW_SECONDS = 300;

    /** Um WAHA lento não pode segurar a fila por causa de um visto. */
    private const BOT_SEEN_TIMEOUT_SECONDS = 3;

    /** `$typing` falso (áudio): o cliente já viu "gravando…", então "digitando…" antes da voz seria incoerente. */
    public function before(WahaClient $client, Ticket $ticket, Contact $contact, string $text, bool $typing = true, MessageOrigin $origin = MessageOrigin::PAINEL): void
    {
        $chatId = $contact->wa_id ?? (string) $contact->lid;
        $bot = $origin === MessageOrigin::BOT;
        $channel = $ticket->channel()->firstOrFail();

        $this->ensureOnline($client, $channel);

        if (! $bot || Cache::add('presence-seen:'.$ticket->id, 1, self::BOT_ROUND_SECONDS)) {
            $this->attempt('visto', fn () => $this->markSeen($client, $ticket, $chatId, $bot ? self::BOT_SEEN_TIMEOUT_SECONDS : null));
        }

        if ($typing) {
            $this->showTyping($client, $channel, $ticket, $chatId, $text, $bot);
        }
    }

    /** O "digitando…" antes do envio: espera artificial, ou nada quando o atendente já digitou de verdade ou o bot já esperou. */
    private function showTyping(WahaClient $client, Channel $channel, Ticket $ticket, string $chatId, string $text, bool $bot): void
    {
        // Atendente digitando ou gravando nesta conversa: o `paused` do bot apagaria o indicador dele.
        $humanActive = $this->isHumanActive($channel, $chatId);
        $botAlreadyTyped = $bot && Cache::pull('presence-typed:'.$ticket->id) !== null;

        if ($botAlreadyTyped || ($bot && $humanActive)) {
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

    /** O bloco Esperar já mostrou o "digitando…": a próxima mensagem do bot não o repete. */
    public function botTyped(Ticket $ticket): void
    {
        Cache::put('presence-typed:'.$ticket->id, 1, now()->addSeconds(self::BOT_TYPED_SECONDS));
    }

    /** "Digitando…" para o cliente enquanto o atendente escreve no campo; `online` antes, como na sequência do WAHA. */
    public function typing(WahaClient $client, Channel $channel, string $chatId, bool $on): void
    {
        if ($on) {
            $this->ensureOnline($client, $channel);
        }

        $this->attempt($on ? 'digitando (campo)' : 'parou de digitar (campo)', fn () => $client->setPresence($on ? 'typing' : 'paused', $chatId));
    }

    /**
     * Uma chamada `online` por janela, não por mensagem nem por aviso de digitação. O WAHA só marca `online` sozinho se
     * `WAHA_PRESENCE_AUTO_ONLINE` estiver ligado (aqui não está); o `offline` (`goOffline`) fecha a janela.
     */
    private function ensureOnline(WahaClient $client, Channel $channel): void
    {
        $key = $this->onlineKey($channel->id);

        if (! Cache::add($key, 1, self::ONLINE_WINDOW_SECONDS)) {
            return;
        }

        $this->attempt('online', function () use ($client, $key): void {
            try {
                $client->setPresence('online');
            } catch (Throwable $e) {
                Cache::forget($key); // não deu certo: o próximo envio tenta de novo

                throw $e;
            }
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

        Cache::forget($this->onlineKey($channel->id));
        $this->attempt('offline', fn () => (new WahaClient($channel))->setPresence('offline'));
    }

    /** Só o que chegou depois do último visto enviado: reenviar os mesmos ids a cada resposta é requisição à toa. */
    private function markSeen(WahaClient $client, Ticket $ticket, string $chatId, ?int $timeoutSeconds = null): void
    {
        $seenUpTo = (int) Cache::get($this->seenKey($ticket->id), 0);

        $received = Message::query()
            ->where('ticket_id', $ticket->id)
            ->where('direction', 'in')
            ->whereNotNull('wa_message_id')
            ->where('id', '>', $seenUpTo)
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
            Cache::put($this->seenKey($ticket->id), (int) $received->max('id'), now()->addDay());
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

    private function onlineKey(int $channelId): string
    {
        return "presence-online:{$channelId}";
    }

    private function seenKey(int $ticketId): string
    {
        return "presence-seen-upto:{$ticketId}";
    }

    private function key(int $channelId): string
    {
        return "presence-offline:{$channelId}";
    }
}
