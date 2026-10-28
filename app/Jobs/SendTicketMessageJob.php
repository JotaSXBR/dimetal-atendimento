<?php

namespace App\Jobs;

use App\Models\Ticket;
use App\Models\User;
use App\Services\Inbox\SendTicketMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendTicketMessageJob implements ShouldQueue
{
    use Queueable;

    // Sem nova tentativa: o serviço grava a linha antes de chamar o WAHA, e repetir duplicaria a mensagem.
    public int $tries = 1;

    public function __construct(
        public readonly int $ticketId,
        public readonly int $authorId,
        public readonly string $text,
    ) {}

    public function handle(SendTicketMessage $send): void
    {
        $send(Ticket::findOrFail($this->ticketId), User::findOrFail($this->authorId), $this->text);
    }
}
