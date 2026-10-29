<?php

namespace App\Services\Inbox;

use App\Enums\ChannelStatus;
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
use Illuminate\Support\Facades\Log;
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
    public function __construct(private readonly Presence $presence) {}

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

        // Sessão fora de WORKING, ou mensagem mais antiga ainda esperando (a ordem da conversa não se perde): fica na fila
        // e a caixa de saída envia quando a conexão voltar.
        if ($channel->status !== ChannelStatus::WORKING || $this->waitingAhead($message)) {
            $this->park($message);

            return;
        }

        // Começa a janela em que o eco `fromMe` ainda pode casar com esta linha.
        $message->touch();

        $client = new WahaClient($channel);
        $this->presence->before($client, $ticket, $contact, $message->body);

        try {
            $response = $client->sendText($contact->wa_id ?? (string) $contact->lid, $message->body);
        } catch (Throwable $e) {
            $this->failed($message, $e);

            return;
        }

        $this->presence->scheduleOffline($channel);

        $message->refresh();

        $waId = $response['id'] ?? null;

        if ($message->wa_message_id === null && is_string($waId) && $waId !== '') {
            $message->wa_message_id = Message::waKey($waId);
        }

        // Aguardando e falha viram enviada; entregue e lida (que o ack pode ter adiantado) ficam como estão.
        if (in_array($message->delivery_status, [null, DeliveryStatus::QUEUED, DeliveryStatus::FAILED], true)) {
            $message->delivery_status = DeliveryStatus::SENT;
            $message->failure_reason = null;
            $message->failure_code = null;
        }

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

    private function waitingAhead(Message $message): bool
    {
        return Message::query()
            ->where('channel_id', $message->channel_id)
            ->where('delivery_status', DeliveryStatus::QUEUED->value)
            ->where('id', '<', $message->id)
            ->exists();
    }

    /** Aguardando conexão: nada saiu, e é para a caixa de saída enviar depois. */
    private function park(Message $message): void
    {
        if ($message->delivery_status !== DeliveryStatus::QUEUED) {
            $message->update(['delivery_status' => DeliveryStatus::QUEUED, 'failure_reason' => null, 'failure_code' => null]);
            MessageStatusChanged::dispatch($message);
        }
    }

    /**
     * O que fazer com um envio que falhou depende de a mensagem poder ter saído (ver `SendFailure`): a que certamente não
     * saiu volta para a fila; as demais viram falha com o motivo, e só o tipo certo ganha botão de reenvio.
     */
    private function failed(Message $message, Throwable $e): void
    {
        // Se o eco do celular já casou com a linha (ela ganhou o id), a mensagem saiu: não é falha.
        if ($message->refresh()->wa_message_id !== null) {
            throw $e;
        }

        $kind = SendFailure::kind($e);

        if ($kind === SendFailure::SESSION_DOWN) {
            $this->park($message);

            return;
        }

        if ($kind === SendFailure::RATE_LIMIT) {
            Log::warning('WhatsApp limitou o envio (463/475): pausar, sem reiniciar nem repareiar a sessão', ['erro' => $e->getMessage()]);
        }

        $message->update([
            'delivery_status' => DeliveryStatus::FAILED,
            'failure_code' => $kind,
            'failure_reason' => SendFailure::reason($kind, $e),
        ]);
        MessageStatusChanged::dispatch($message);

        throw $e;
    }
}
