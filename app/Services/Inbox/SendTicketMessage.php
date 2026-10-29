<?php

namespace App\Services\Inbox;

use App\Enums\DeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Events\MessageCreated;
use App\Events\MessageStatusChanged;
use App\Events\TicketUpdated;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Services\Waha\WahaClient;
use Illuminate\Support\Str;
use Throwable;

/**
 * Envia um texto do painel para o cliente do ticket, em dois passos.
 *
 * `create` grava a linha no clique, para o atendente ver a própria mensagem na hora e para o eco `fromMe` que pode chegar
 * antes da resposta HTTP encontrar a mensagem pendente em vez de gravar uma segunda. `deliver` fala com o WAHA e roda
 * na fila de saída. Permissão é da Policy.
 */
class SendTicketMessage
{
    public function __invoke(Ticket $ticket, User $author, string $text): Message
    {
        $message = $this->create($ticket, $author, $text);
        $this->deliver($message);

        return $message;
    }

    public function create(Ticket $ticket, User $author, string $text): Message
    {
        $message = Message::create([
            'ticket_id' => $ticket->id,
            'channel_id' => $ticket->channel_id,
            'contact_id' => $ticket->contact_id,
            'direction' => MessageDirection::OUT,
            'type' => 'text',
            'body' => $text,
            'origin' => MessageOrigin::PAINEL,
            'author_user_id' => $author->id,
            'sent_at' => now(),
        ]);

        MessageCreated::dispatch($message);

        return $message;
    }

    public function deliver(Message $message): void
    {
        $ticket = $message->ticket()->firstOrFail();
        $contact = $ticket->contact()->firstOrFail();
        $channel = $ticket->channel()->firstOrFail();

        try {
            $response = (new WahaClient($channel))->sendText($contact->wa_id ?? (string) $contact->lid, $message->body);
        } catch (Throwable $e) {
            // Se o eco do celular já casou com a linha (ela ganhou o id), a mensagem saiu: não é falha.
            if ($message->refresh()->wa_message_id === null) {
                $message->update(['delivery_status' => DeliveryStatus::FAILED, 'failure_reason' => Str::limit($e->getMessage(), 250)]);
                MessageStatusChanged::dispatch($message);
            }

            throw $e;
        }

        $message->refresh();

        $waId = $response['id'] ?? null;

        if ($message->wa_message_id === null && is_string($waId) && $waId !== '') {
            $message->wa_message_id = Message::waKey($waId);
        }

        $message->delivery_status ??= DeliveryStatus::SENT;
        $message->save();

        $ticket->recordOutbound($message->sent_at);
        MessageStatusChanged::dispatch($message);
        TicketUpdated::dispatch($ticket);

        TicketEvent::create([
            'ticket_id' => $ticket->id,
            'type' => 'message_out',
            'actor_user_id' => $message->author_user_id,
            'payload' => ['message_id' => $message->id],
        ]);
    }
}
