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

    /** Só a mensagem do cliente abre a janela; o eco de uma mensagem nossa (`fromMe`) não. */
    public static function whenClientWrote(int $ticketId, bool $fromMe): void
    {
        $fromMe || self::dispatch($ticketId)->afterCommit();
    }

    public function handle(Presence $presence): void
    {
        $ticket = Ticket::query()->find($this->ticketId);
        $channel = $ticket?->channel()->first();
        $contact = $ticket?->contact()->first();

        if ($channel === null || $contact === null || ! $channel->isConnected()) {
            return;
        }

        $chatId = $contact->wa_id ?? (string) $contact->lid;

        if ($chatId !== '') {
            $presence->openClientWindow(new WahaClient($channel), $channel, $chatId);
        }
    }
}
