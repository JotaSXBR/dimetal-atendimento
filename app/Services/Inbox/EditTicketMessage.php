<?php

namespace App\Services\Inbox;

use App\Models\Message;
use App\Models\TicketEvent;
use App\Models\User;
use App\Services\Waha\WahaClient;
use App\Services\Waha\WahaMessageActions;

/**
 * Editar uma mensagem de texto enviada pelo painel: pede ao WAHA e grava o evento de auditoria. O corpo novo e a versão
 * anterior entram pelo eco do WhatsApp (`ApplyMessageChange::edited`), como já acontece com a edição do cliente.
 */
class EditTicketMessage
{
    /** O mesmo limite de uma mensagem de texto do WhatsApp na tela. */
    public const LIMIT = 4096;

    /** @throws MessageActionFailed sem permissão ou fora da janela, texto inválido ou recusa do WAHA */
    public function __invoke(Message $message, User $actor, string $text): void
    {
        $text = trim($text);

        if (! $actor->can('edit', $message)) {
            throw new MessageActionFailed('Esta mensagem não pode mais ser editada (só a sua, até '.Message::EDIT_WINDOW_MINUTES.' minutos depois do envio).');
        }

        $problem = match (true) {
            $text === '' => 'Escreva o novo texto.',
            $text === $message->body => 'O texto não mudou.',
            mb_strlen($text) > self::LIMIT => 'A mensagem passa de '.self::LIMIT.' caracteres.',
            default => null,
        };

        if ($problem !== null) {
            throw new MessageActionFailed($problem);
        }

        $actions = new WahaMessageActions(new WahaClient($message->channel()->firstOrFail()));
        $actions->edit((string) $message->chatId(), (string) $message->fullWaId(), $text);

        TicketEvent::create([
            'ticket_id' => $message->ticket_id,
            'type' => 'message_edited',
            'actor_user_id' => $actor->id,
            'payload' => ['message_id' => $message->id],
        ]);
    }
}
