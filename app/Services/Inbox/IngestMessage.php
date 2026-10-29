<?php

namespace App\Services\Inbox;

use App\Enums\DeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Enums\TicketStatus;
use App\Events\MessageCreated;
use App\Events\TicketUpdated;
use App\Jobs\ResolveMessageMediaJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\WahaEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

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
        $incoming = $channel === null ? null : IncomingMessage::fromEvent($event);

        if ($channel === null || $incoming === null) {
            return null;
        }

        if (($existing = $this->find($channel, $incoming->waId)) !== null) {
            return $existing;
        }

        $contact = ($this->resolveContact)($incoming->peer, $incoming->alternateJid, $incoming->pushName);

        if ($incoming->fromMe && ($pending = $this->matchPending($channel, $contact, $incoming->body)) !== null) {
            return $this->adoptEcho($pending, $incoming);
        }

        return $this->storeOnce($channel, $contact, $incoming);
    }

    private function find(Channel $channel, string $waId): ?Message
    {
        return Message::query()->where('channel_id', $channel->id)->where('wa_message_id', $waId)->first();
    }

    /**
     * O eco da mensagem do painel chegou: a linha local ganha o id do WhatsApp em vez de virar uma segunda.
     */
    private function adoptEcho(Message $pending, IncomingMessage $incoming): Message
    {
        $ticket = $pending->ticket()->firstOrFail();

        $pending->update(['wa_message_id' => $incoming->waId]);
        $ticket->recordOutbound($incoming->sentAt);
        TicketUpdated::dispatch($ticket);

        return $pending;
    }

    private function storeOnce(Channel $channel, Contact $contact, IncomingMessage $incoming): Message
    {
        try {
            return DB::transaction(fn () => $this->store($channel, $contact, $incoming));
        } catch (UniqueConstraintViolationException $e) {
            // Corrida com outro job da mesma mensagem: a linha dele vale, a nossa é descartada.
            return $this->find($channel, $incoming->waId) ?? throw $e;
        }
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

    private function store(Channel $channel, Contact $contact, IncomingMessage $incoming): Message
    {
        $ticket = $this->openTicketFor($channel, $contact, $incoming);

        $incoming->fromMe ? $ticket->recordOutbound($incoming->sentAt) : $ticket->recordInbound($incoming->sentAt);

        $message = Message::create([
            'ticket_id' => $ticket->id,
            'channel_id' => $channel->id,
            'contact_id' => $contact->id,
            'wa_message_id' => $incoming->waId,
            'direction' => $incoming->fromMe ? MessageDirection::OUT : MessageDirection::IN,
            'type' => $incoming->type,
            'body' => $incoming->body,
            'media_ref' => $incoming->mediaRef,
            'media_mime' => $incoming->mediaMime,
            'media_name' => $incoming->mediaName,
            'media_size' => $incoming->mediaSize,
            'origin' => $incoming->fromMe ? MessageOrigin::FORA_DO_PAINEL : MessageOrigin::CONTATO,
            'sent_at' => $incoming->sentAt,
            'delivery_status' => $incoming->fromMe ? DeliveryStatus::SENT : null,
        ]);

        TicketEvent::create([
            'ticket_id' => $ticket->id,
            'type' => $incoming->fromMe ? 'message_out' : 'message_in',
            'payload' => ['message_id' => $message->id],
        ]);

        MessageCreated::dispatch($message);
        TicketUpdated::dispatch($ticket);

        if ($message->hasMedia() && $message->media_ref === null) {
            ResolveMessageMediaJob::dispatch($message->id)->afterCommit();
        }

        return $message;
    }

    private function openTicketFor(Channel $channel, Contact $contact, IncomingMessage $incoming): Ticket
    {
        // Serializa a abertura de ticket por contato e canal: duas mensagens simultâneas do mesmo cliente
        // não disputam o índice único (o job perdedor perderia a mensagem, pois o WAHA não reenvia).
        DB::select('select pg_advisory_xact_lock(hashtext(?))', ['ticket:'.$contact->id.':'.$channel->id]);

        $ticket = Ticket::query()
            ->open()
            ->where('contact_id', $contact->id)
            ->where('channel_id', $channel->id)
            ->first();

        if ($ticket !== null) {
            return $ticket;
        }

        $ticket = Ticket::create([
            'contact_id' => $contact->id,
            'channel_id' => $channel->id,
            'status' => $incoming->fromMe ? TicketStatus::EM_ATENDIMENTO : TicketStatus::AGUARDANDO,
            'opened_at' => $incoming->sentAt,
        ]);

        TicketEvent::create(['ticket_id' => $ticket->id, 'type' => 'opened']);

        return $ticket;
    }
}
