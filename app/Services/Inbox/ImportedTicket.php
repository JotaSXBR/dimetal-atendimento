<?php

namespace App\Services\Inbox;

use App\Enums\ClosedReason;
use App\Enums\TicketStatus;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

/**
 * O ticket encerrado de importação de uma mensagem: o contato tem vários, separados por pausas de mais de `GAP_HOURS` sem
 * mensagem. A decisão é da própria mensagem (a janela do ticket a alcança ou não), então a importação segue idempotente e
 * retomável; a que fecha o buraco entre dois tickets os funde no mais antigo.
 */
class ImportedTicket
{
    public const GAP_HOURS = 6;

    public function __invoke(Channel $channel, Contact $contact, IncomingMessage $incoming): Ticket
    {
        // Mesmo lock do ticket aberto (`IngestMessage`): mensagens simultâneas do contato não criam tickets em duplicidade.
        DB::select('select pg_advisory_xact_lock(hashtext(?))', ['ticket:'.$contact->id.':'.$channel->id]);

        $sentAt = $incoming->sentAt;
        $near = Ticket::query()
            ->where('contact_id', $contact->id)
            ->where('channel_id', $channel->id)
            ->where('closed_reason', ClosedReason::IMPORTADO->value)
            ->where('opened_at', '<=', $sentAt->addHours(self::GAP_HOURS))
            ->where('closed_at', '>=', $sentAt->subHours(self::GAP_HOURS))
            ->orderBy('opened_at')
            ->get();

        $ticket = $near->shift();

        if ($ticket === null) {
            return Ticket::create([
                'contact_id' => $contact->id,
                'channel_id' => $channel->id,
                'status' => TicketStatus::ENCERRADO,
                'opened_at' => $sentAt,
                'closed_at' => $sentAt,
                'closed_reason' => ClosedReason::IMPORTADO,
            ]);
        }

        foreach ($near as $other) {
            Message::query()->where('ticket_id', $other->id)->update(['ticket_id' => $ticket->id]);
            $ticket->closed_at = $ticket->closed_at?->max($other->closed_at);
            $other->delete();
        }

        $ticket->opened_at = $ticket->opened_at->min($sentAt);
        $ticket->closed_at = $ticket->closed_at === null ? $sentAt : $ticket->closed_at->max($sentAt);
        $ticket->save();

        return $ticket;
    }
}
