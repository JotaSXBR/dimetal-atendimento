<?php

namespace App\Services\Inbox;

use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\TicketEvent;
use App\Models\User;
use App\Services\Waha\WahaClient;
use App\Services\Waha\WahaMessageActions;

/**
 * Reagir a uma mensagem pelo painel: pede ao WAHA e grava o evento de auditoria. A reação em si entra no painel quando o
 * eco do WhatsApp chega (`ApplyMessageChange::reaction`), e é isso que prova que o cliente a viu.
 */
class ReactToMessage
{
    /** A barra rápida do menu da bolha; o seletor completo de emoji está fora do pacote. */
    public const EMOJIS = ['👍', '❤️', '😂', '😮', '😢', '🙏'];

    /** @throws MessageActionFailed emoji fora da lista, mensagem ainda sem id do WhatsApp ou recusa do WAHA */
    public function __invoke(Message $message, User $actor, string $emoji): void
    {
        if (! in_array($emoji, self::EMOJIS, true)) {
            throw new MessageActionFailed('Emoji não permitido.');
        }

        $fullId = $message->fullWaId() ?? throw new MessageActionFailed('A mensagem ainda não foi enviada ao WhatsApp.');

        // O mesmo emoji de novo tira a reação (emoji vazio no WAHA), como no WhatsApp.
        $current = MessageReaction::query()->where('message_id', $message->id)->where('by', 'me')->value('emoji');
        $send = $current === $emoji ? '' : $emoji;

        (new WahaMessageActions(new WahaClient($message->channel()->firstOrFail())))->react($fullId, $send);

        TicketEvent::create([
            'ticket_id' => $message->ticket_id,
            'type' => 'message_reacted',
            'actor_user_id' => $actor->id,
            'payload' => ['message_id' => $message->id, 'emoji' => $send === '' ? null : $send],
        ]);
    }
}
