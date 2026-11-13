<?php

namespace App\Support;

use App\Enums\MessageDirection;
use App\Enums\TagScope;
use App\Models\Message;
use App\Models\Tag;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;

/** Consultas da lista da Fila que não são do modelo: o contador de não lidas e o filtro por etiqueta. Usadas com `->tap()`. */
final class TicketQueue
{
    /**
     * Soma `unread` à lista: as mensagens do cliente depois do último visto do atendente (todas, se ainda não houve visto).
     *
     * @param  Builder<Ticket>  $query
     */
    public static function withUnread(Builder $query): void
    {
        $query->addSelect(['unread' => Message::query()
            ->selectRaw('count(*)')
            ->whereColumn('messages.ticket_id', 'tickets.id')
            ->where('messages.direction', MessageDirection::IN->value)
            ->whereRaw('(tickets.agent_seen_at is null or messages.sent_at > tickets.agent_seen_at)')]);
    }

    /**
     * Só os tickets com a etiqueta: no contato do ticket (escopo `contato`) ou no próprio ticket (escopo `conversa`).
     * Etiqueta que não existe mais não casa com nada.
     *
     * @param  Builder<Ticket>  $query
     */
    public static function withTag(Builder $query, int $tagId): void
    {
        $relation = Tag::query()->find($tagId)?->scope === TagScope::CONTATO ? 'contact.tags' : 'tags';

        $query->whereHas($relation, fn (Builder $tags) => $tags->whereKey($tagId));
    }
}
