<?php

namespace App\Services\Inbox;

use App\Enums\DeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Services\Waha\WahaClient;
use Throwable;

/**
 * Envia um texto do painel para o cliente do ticket.
 *
 * A linha local nasce ANTES da chamada ao WAHA, para o eco `fromMe` que pode chegar antes da
 * resposta HTTP encontrar a mensagem pendente em vez de gravar uma segunda. Permissão é da Policy.
 */
class SendTicketMessage
{
    public function __invoke(Ticket $ticket, User $author, string $text): Message
    {
        $contact = $ticket->contact;

        $message = Message::create([
            'ticket_id' => $ticket->id,
            'channel_id' => $ticket->channel_id,
            'contact_id' => $contact->id,
            'direction' => MessageDirection::OUT,
            'type' => 'text',
            'body' => $text,
            'origin' => MessageOrigin::PAINEL,
            'author_user_id' => $author->id,
            'sent_at' => now(),
        ]);

        try {
            $response = (new WahaClient($ticket->channel))->sendText($contact->wa_id ?? (string) $contact->lid, $text);
        } catch (Throwable $e) {
            $message->update(['delivery_status' => DeliveryStatus::FAILED]);

            throw $e;
        }

        $message->refresh();

        $waId = $response['id'] ?? null;

        if ($message->wa_message_id === null && is_string($waId) && $waId !== '') {
            $message->wa_message_id = $waId;
        }

        $message->delivery_status ??= DeliveryStatus::SENT;
        $message->save();

        $ticket->first_human_reply_at ??= $message->sent_at;
        $ticket->last_outbound_at = $message->sent_at;
        $ticket->save();

        TicketEvent::create([
            'ticket_id' => $ticket->id,
            'type' => 'message_out',
            'actor_user_id' => $author->id,
            'payload' => ['message_id' => $message->id],
        ]);

        return $message;
    }
}
