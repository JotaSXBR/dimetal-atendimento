<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\Inbox\SendTicketMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendTicketMessageJob implements ShouldQueue
{
    use Queueable;

    // Sem nova tentativa: a linha já existe e repetir duplicaria a mensagem no WhatsApp.
    public int $tries = 1;

    // Visto, digitando, espera e envio chegam a mais de 60 s no pior caso. `retry_after` da fila fica acima disto.
    public int $timeout = 120;

    public function __construct(public readonly int $messageId)
    {
        // Fila própria com um único processo: preserva a ordem das mensagens de cada conversa.
        $this->onQueue('outbound');
    }

    public function handle(SendTicketMessage $send): void
    {
        $send->deliver(Message::findOrFail($this->messageId));
    }
}
