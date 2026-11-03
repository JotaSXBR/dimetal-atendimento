<?php

namespace App\Services\Inbox;

use App\Models\Channel;
use App\Models\Message;
use App\Models\WahaEvent;
use App\Services\Waha\WahaClient;
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

        foreach ((new WahaClient($channel))->messages($from, $to) as $data) {
            $message = $this->ingest($channel, $data);

            if ($message?->wasRecentlyCreated === true) {
                $messages++;
                $tickets[$message->ticket_id] = true;
            }
        }

        return new RecoveryResult($messages, count($tickets));
    }

    /** @param  array<string, mixed>  $data */
    private function ingest(Channel $channel, array $data): ?Message
    {
        if (! is_string($data['id'] ?? null) || $data['id'] === '') {
            return null;
        }

        try {
            $event = WahaEvent::query()->firstOrCreate(
                ['waha_event_id' => 'recovered:'.$channel->id.':'.Message::waKey($data['id'])],
                [
                    'channel_id' => $channel->id,
                    'event' => 'message',
                    'session' => $channel->waha_session,
                    'payload' => ['event' => 'message', 'session' => $channel->waha_session, 'payload' => $data, 'mode' => IngestMode::RECOVERED->value],
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
