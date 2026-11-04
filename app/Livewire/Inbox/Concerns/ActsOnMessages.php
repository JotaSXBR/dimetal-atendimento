<?php

namespace App\Livewire\Inbox\Concerns;

use App\Models\Message;
use App\Services\Inbox\Quote;

/**
 * As ações do menu da bolha (responder, reagir, editar, apagar). O componente que usa precisa oferecer `selected()` (o ticket
 * na tela, já filtrado pela visibilidade) e `user()`. O id da mensagem vem do navegador: só vale se for do ticket aberto.
 */
trait ActsOnMessages
{
    /** A mensagem que o próximo envio vai citar. */
    public ?int $replyToId = null;

    /** Responder: a barra aparece sobre o campo e o próximo envio sai citando esta mensagem. */
    public function reply(int $messageId): void
    {
        $message = Message::query()->where('ticket_id', $this->selected()->id)->whereNotNull('wa_message_id')->find($messageId);

        if ($message === null) {
            return;
        }

        $this->authorize('reply', $message);
        $this->replyToId = $message->id;
    }

    public function cancelReply(): void
    {
        $this->replyToId = null;
    }

    /** A mensagem citada, ainda do ticket aberto; o navegador pode ter guardado um id antigo. */
    private function quotedMessage(int $ticketId): ?Message
    {
        return $this->replyToId === null ? null : Message::query()->where('ticket_id', $ticketId)->whereNotNull('wa_message_id')->find($this->replyToId);
    }

    /** @return array{author: string, type: string, excerpt: string}|null o que a barra "Respondendo a…" mostra */
    private function replyBar(?int $ticketId): ?array
    {
        $message = $ticketId === null ? null : $this->quotedMessage($ticketId);

        return $message === null ? null : Quote::snapshot($message);
    }
}
