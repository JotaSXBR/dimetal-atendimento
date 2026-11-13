<?php

namespace App\Actions\Inbox;

use App\Enums\MessageDirection;
use App\Enums\TicketStatus;
use App\Events\TicketUpdated;
use App\Models\Ticket;
use App\Models\User;

/**
 * O atendente viu a conversa: zera as não lidas da Fila. Só vale para o responsável do ticket (ou para quem abre um ticket sem
 * responsável); quem só espia (supervisor, admin) não zera o contador de quem atende.
 */
class MarkTicketSeenByAgent
{
    public function __invoke(Ticket $ticket, User $by): void
    {
        $mine = $ticket->assigned_user_id === null || $ticket->assigned_user_id === $by->id;

        if (! $mine || $ticket->status === TicketStatus::ENCERRADO) {
            return;
        }

        // A marca é o id da última mensagem do cliente lida AGORA: o que chegar depois tem id maior e segue não lido (sem corrida,
        // nem com duas mensagens no mesmo segundo). Sem mensagem do cliente depois da marca não há o que zerar: nada de gravar
        // nem de avisar a Fila.
        $max = $ticket->messages()->where('direction', MessageDirection::IN->value)->max('id');
        $latestId = is_numeric($max) ? (int) $max : null;

        if ($ticket->agent_seen_at !== null && ($latestId === null || $latestId <= ($ticket->agent_seen_message_id ?? 0))) {
            return;
        }

        // Consulta direta: o visto não é uma edição do ticket (não mexe em `updated_at`).
        Ticket::query()->whereKey($ticket->id)->update(['agent_seen_at' => now(), 'agent_seen_message_id' => $latestId]);
        $ticket->refresh();

        TicketUpdated::dispatch($ticket);
    }
}
