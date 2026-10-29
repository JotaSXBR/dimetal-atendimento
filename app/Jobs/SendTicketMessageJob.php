<?php

namespace App\Jobs;

use App\Models\Message;
use App\Services\Inbox\OutboxRecovery;
use App\Services\Inbox\SendTicketMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

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
     * para sempre. Pode ter saído antes da morte, então é falha "sem resposta" (confirmar antes de reenviar). Se a mensagem
     * já tem id ou estado, o envio aconteceu e nada muda.
     */
    public function failed(\Throwable $e): void
    {
        Log::warning('Envio morreu antes de confirmar', ['message' => $this->messageId, 'erro' => $e->getMessage()]);

        app(OutboxRecovery::class)->markUnknown(Message::query()->whereKey($this->messageId));
    }
}
