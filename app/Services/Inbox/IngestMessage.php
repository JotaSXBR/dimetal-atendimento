<?php

namespace App\Services\Inbox;

use App\Enums\DeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Enums\TicketStatus;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\WahaEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Transforma um evento `message`/`message.any` do WAHA em contato + ticket + mensagem.
 *
 * Idempotente: o mesmo `wa_message_id` no mesmo canal nunca gera segunda linha.
 */
class IngestMessage
{
    /** Janela para casar o eco `fromMe` com a mensagem que o painel gravou antes de enviar. */
    private const ECHO_WINDOW_SECONDS = 60;

    public function __construct(private readonly ResolveContact $resolveContact) {}

    public function __invoke(WahaEvent $event): ?Message
    {
        $channel = $event->channel;
        $data = $event->payload['payload'] ?? null;

        if ($channel === null || ! is_array($data)) {
            return null;
        }

        $waId = $data['id'] ?? null;
        $fromMe = (bool) ($data['fromMe'] ?? false);

        if (! is_string($waId) || $waId === '' || ($event->event === 'message.any' && ! $fromMe)) {
            return null;
        }

        $peer = $fromMe ? ($data['to'] ?? null) : ($data['from'] ?? null);

        if (! is_string($peer) || $this->isIgnoredChat($peer)) {
            Log::info('Mensagem do WAHA ignorada (grupo ou broadcast)', ['peer' => $peer, 'event' => $event->waha_event_id]);

            return null;
        }

        if (($existing = $this->find($channel, $waId)) !== null) {
            return $existing;
        }

        $hasMedia = (bool) ($data['hasMedia'] ?? false);
        $body = $hasMedia ? '' : (is_string($data['body'] ?? null) ? $data['body'] : '');
        $type = $this->typeFor($data, $hasMedia, $body);

        if ($type === null) {
            Log::info('Mensagem do WAHA sem conteúdo ignorada', ['id' => $waId, 'event' => $event->waha_event_id]);

            return null;
        }

        $info = $data['_data']['Info'] ?? [];
        $contact = ($this->resolveContact)(
            $peer,
            is_string($alt = $info[$fromMe ? 'RecipientAlt' : 'SenderAlt'] ?? null) ? $alt : null,
            ! $fromMe && is_string($name = $info['PushName'] ?? $data['notifyName'] ?? null) ? $name : null,
        );

        $sentAt = $this->sentAt($data['timestamp'] ?? null, $event->received_at);

        if ($fromMe && ($pending = $this->matchPending($channel, $contact, $body)) !== null) {
            $pending->update(['wa_message_id' => $waId]);
            $pending->ticket->recordOutbound($sentAt);

            return $pending;
        }

        try {
            return DB::transaction(fn () => $this->store($channel, $contact, $waId, $fromMe, $type, $body, $sentAt));
        } catch (UniqueConstraintViolationException $e) {
            // Corrida com outro job da mesma mensagem: a linha dele vale, a nossa é descartada.
            return Message::query()
                ->where('channel_id', $channel->id)
                ->where('wa_message_id', $waId)
                ->firstOr(fn () => throw $e);
        }
    }

    private function isIgnoredChat(string $chat): bool
    {
        return str_ends_with($chat, '@g.us') || $chat === 'status@broadcast' || str_ends_with($chat, '@newsletter');
    }

    private function find(Channel $channel, string $waId): ?Message
    {
        return Message::query()->where('channel_id', $channel->id)->where('wa_message_id', $waId)->first();
    }

    /**
     * Tipo da mensagem, ou null quando não há nada para mostrar (sem texto, sem mídia e sem tipo não textual).
     *
     * @param  array<string, mixed>  $data
     */
    private function typeFor(array $data, bool $hasMedia, string $body): ?string
    {
        if ($hasMedia) {
            return 'media';
        }

        if ($body !== '') {
            return 'text';
        }

        $type = $data['type'] ?? null;

        return is_string($type) && ! in_array($type, ['', 'chat', 'text'], true) ? $type : null;
    }

    private function sentAt(mixed $timestamp, CarbonImmutable $fallback): CarbonImmutable
    {
        // Timestamp ausente, zero ou negativo não é data de verdade: usa o horário de recebimento.
        return is_numeric($timestamp) && (int) $timestamp > 0
            ? CarbonImmutable::createFromTimestamp((int) $timestamp, config('app.timezone'))
            : $fallback;
    }

    private function matchPending(Channel $channel, Contact $contact, string $body): ?Message
    {
        return Message::query()
            ->where('channel_id', $channel->id)
            ->where('contact_id', $contact->id)
            ->where('direction', MessageDirection::OUT->value)
            ->where('origin', MessageOrigin::PAINEL->value)
            ->whereNull('wa_message_id')
            ->whereNull('delivery_status')
            ->where('body', $body)
            ->where('created_at', '>=', now()->subSeconds(self::ECHO_WINDOW_SECONDS))
            ->oldest('id')
            ->first();
    }

    private function store(Channel $channel, Contact $contact, string $waId, bool $fromMe, string $type, string $body, CarbonImmutable $sentAt): Message
    {
        // Serializa a abertura de ticket por contato e canal: duas mensagens simultâneas do mesmo cliente
        // não disputam o índice único (o job perdedor perderia a mensagem, pois o WAHA não reenvia).
        DB::select('select pg_advisory_xact_lock(hashtext(?))', ['ticket:'.$contact->id.':'.$channel->id]);

        $ticket = Ticket::query()
            ->open()
            ->where('contact_id', $contact->id)
            ->where('channel_id', $channel->id)
            ->first();

        if ($ticket === null) {
            $ticket = Ticket::create([
                'contact_id' => $contact->id,
                'channel_id' => $channel->id,
                'status' => $fromMe ? TicketStatus::EM_ATENDIMENTO : TicketStatus::AGUARDANDO,
                'opened_at' => $sentAt,
            ]);

            TicketEvent::create(['ticket_id' => $ticket->id, 'type' => 'opened']);
        }

        $fromMe ? $ticket->recordOutbound($sentAt) : $ticket->recordInbound($sentAt);

        $message = Message::create([
            'ticket_id' => $ticket->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'wa_message_id' => $waId,
            'direction' => $fromMe ? MessageDirection::OUT : MessageDirection::IN,
            'type' => $type,
            'body' => $body,
            'origin' => $fromMe ? MessageOrigin::FORA_DO_PAINEL : MessageOrigin::CONTATO,
            'sent_at' => $sentAt,
            'delivery_status' => $fromMe ? DeliveryStatus::SENT : null,
        ]);

        TicketEvent::create([
            'ticket_id' => $ticket->id,
            'type' => $fromMe ? 'message_out' : 'message_in',
            'payload' => ['message_id' => $message->id],
        ]);

        return $message;
    }
}
