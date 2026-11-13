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

        // Sem mensagem do cliente depois do último visto não há o que zerar: nada de gravar nem de avisar a Fila.
        if ($ticket->agent_seen_at !== null && ! $ticket->messages()->where('direction', MessageDirection::IN->value)->where('sent_at', '>', $ticket->agent_seen_at)->exists()) {
            return;
        }

        // Grava até onde a pessoa viu (a última mensagem do cliente), não "agora": o que chegar depois deste instante segue não lido.
        // shortcut: `sent_at` tem precisão de segundo e a comparação é `>`; duas mensagens no mesmo segundo (álbum, rajada) com a aba escondida
        // podem escapar do contador. Marca d'água por id de mensagem resolve, se algum dia incomodar.
        // Consulta direta: o visto não é uma edição do ticket (não mexe em `updated_at`).
        $latest = $ticket->messages()->where('direction', MessageDirection::IN->value)->max('sent_at');
        Ticket::query()->whereKey($ticket->id)->update(['agent_seen_at' => $latest ?? now()]);
        $ticket->refresh();

        TicketUpdated::dispatch($ticket);
    }
}
