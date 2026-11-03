<?php

namespace App\Services\Inbox;

use App\Models\Channel;
use App\Models\Message;
use App\Models\WahaEvent;
use App\Services\Waha\WahaClient;
use App\Services\Waha\WahaMessages;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Busca no WAHA as mensagens de uma janela e as entrega ao `IngestMessage` como eventos sintéticos do modo `recovered`.
 * Pode rodar quantas vezes for preciso: o id sintético e o `wa_message_id` do canal impedem a duplicata.
 */
class RecoverMessages
{
    /** O GOWS guarda só alguns dias de histórico; pedir mais só custa páginas vazias. */
    private const MAX_WINDOW_DAYS = 7;

    public function __invoke(Channel $channel, CarbonInterface $from, CarbonInterface $to): RecoveryResult
    {
        $to = CarbonImmutable::instance($to);
        $from = CarbonImmutable::instance($from)->max($to->subDays(self::MAX_WINDOW_DAYS));

        $messages = 0;
        $tickets = [];

        foreach ((new WahaMessages(new WahaClient($channel)))->between($from, $to) as $data) {
            $message = $this->ingest($channel, $data, IngestMode::RECOVERED);

            if ($message?->wasRecentlyCreated === true) {
                $messages++;
                $tickets[$message->ticket_id] = true;
            }
        }

        return new RecoveryResult($messages, count($tickets));
    }

    /**
     * Grava uma mensagem da listagem do WAHA pelo caminho do webhook, no modo dado. Nunca lança: devolve `null` se falhar.
     *
     * @param  array<string, mixed>  $data
     */
    public function ingest(Channel $channel, array $data, IngestMode $mode): ?Message
    {
        if (! is_string($data['id'] ?? null) || $data['id'] === '') {
            return null;
        }

        // A varredura diária revê o dia inteiro: o que o webhook já gravou não ganha uma segunda cópia do envelope.
        if (Message::query()->where('channel_id', $channel->id)->where('wa_message_id', Message::waKey($data['id']))->exists()) {
            return null;
        }

        try {
            $event = WahaEvent::query()->firstOrCreate(
                ['waha_event_id' => $mode->value.':'.$channel->id.':'.Message::waKey($data['id'])],
                [
                    'channel_id' => $channel->id,
                    'event' => 'message',
                    'session' => $channel->waha_session,
                    'payload' => ['event' => 'message', 'session' => $channel->waha_session, 'payload' => $data, 'mode' => $mode->value],
                    'received_at' => now(),
                ],
            );

            return app(IngestMessage::class)($event);
        } catch (Throwable $e) {
            // Uma mensagem ruim não pode impedir as outras; a próxima execução tenta de novo.
            Log::warning('Recuperação: mensagem não gravada', ['canal' => $channel->id, 'id' => $data['id'], 'erro' => $e->getMessage()]);

            return null;
        }
    }
}
