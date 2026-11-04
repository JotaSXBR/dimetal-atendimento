<?php

namespace App\Jobs;

use App\Models\Ticket;
use App\Services\Inbox\Presence;
use App\Services\Waha\WahaClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Abre a janela de presença do cliente deste ticket: online, assinatura do chat e `offline` agendado. Melhor esforço. */
class SubscribeClientPresenceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $ticketId)
    {
        $this->onQueue('realtime');
    }

    public function handle(Presence $presence): void
    {
        $ticket = Ticket::query()->with(['contact', 'channel'])->find($this->ticketId);

        if ($ticket === null || ! $ticket->channel->isConnected()) {
            return;
        }

        $contact = $ticket->contact;
        $chatId = $contact->wa_id ?? (string) $contact->lid;

        if ($chatId !== '') {
            $presence->openClientWindow(new WahaClient($ticket->channel), $ticket->channel, $chatId);
        }
    }
}
