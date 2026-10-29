<?php

namespace App\Jobs;

use App\Enums\DeliveryStatus;
use App\Models\Message;
use App\Services\Inbox\SendTicketMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

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

    /**
     * Morte fora do `try` do envio (worker derrubado no deploy, erro antes do WAHA): sem isto a linha ficaria "enviando"
     * para sempre. Se a mensagem já tem id ou estado, o envio aconteceu e nada muda.
     */
    public function failed(\Throwable $e): void
    {
        Message::query()
            ->whereKey($this->messageId)
            ->whereNull('wa_message_id')
            ->whereNull('delivery_status')
            ->update(['delivery_status' => DeliveryStatus::FAILED, 'failure_reason' => Str::limit($e->getMessage(), 250)]);
    }
}
