<?php

namespace App\Services\Inbox;

use App\Models\Message;
use App\Models\TicketEvent;
use App\Models\User;
use App\Services\Waha\WahaClient;
use App\Services\Waha\WahaMessageActions;

/**
 * Apagar para todos uma mensagem enviada pelo painel: pede ao WAHA e grava o evento de auditoria com o autor. O painel só
 * marca "apagada" quando o eco `message.revoked` chega: o WAHA responde "ok" também quando o WhatsApp apagou só no celular da
 * empresa (fora do limite dele), e o texto original continua visível.
 */
class RevokeTicketMessage
{
    /** @throws MessageActionFailed sem permissão ou recusa do WAHA */
    public function __invoke(Message $message, User $actor): void
    {
        if (! $actor->can('revoke', $message)) {
            throw new MessageActionFailed('Só a sua própria mensagem, já enviada, pode ser apagada para todos.');
        }

        (new WahaMessageActions(new WahaClient($message->channel()->firstOrFail())))
            ->revoke((string) $message->chatId(), (string) $message->fullWaId());

        TicketEvent::create([
            'ticket_id' => $message->ticket_id,
            'type' => 'message_revoked_by_agent',
            'actor_user_id' => $actor->id,
            'payload' => ['message_id' => $message->id],
        ]);
    }
}
