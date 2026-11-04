<?php

namespace App\Livewire\Inbox\Concerns;

use App\Models\Message;
use App\Services\Inbox\MessageActionFailed;
use App\Services\Inbox\Quote;
use App\Services\Inbox\ReactToMessage;
use Closure;
use Illuminate\Support\Facades\RateLimiter;

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

    /** Reagir com um dos emojis da barra; o mesmo emoji de novo remove. A reação aparece quando o WhatsApp confirma. */
    public function react(int $messageId, string $emoji): void
    {
        $this->act($messageId, 'react', fn (Message $message) => app(ReactToMessage::class)($message, $this->user(), $emoji));
    }

    /**
     * O caminho comum das ações que chamam o WhatsApp: a mensagem tem de ser do ticket aberto (o id vem do navegador), o usuário
     * precisa ter a permissão, há um teto por usuário e a recusa vira aviso na tela, sem mudar nada no painel.
     *
     * @param  Closure(Message): mixed  $action
     */
    private function act(int $messageId, string $ability, Closure $action): void
    {
        $message = Message::query()->where('ticket_id', $this->selected()->id)->find($messageId);

        if ($message === null) {
            return;
        }

        $this->authorize($ability, $message);

        if (! RateLimiter::attempt('message-action:'.$this->user()->id, config()->integer('security.message_action_per_minute'), fn () => true, 60)) {
            $this->notice = 'Você está agindo rápido demais. Aguarde alguns segundos.';

            return;
        }

        try {
            $action($message);
            $this->notice = null;
        } catch (MessageActionFailed $e) {
            $this->notice = $e->getMessage();
        }
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
