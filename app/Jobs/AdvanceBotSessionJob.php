<?php

namespace App\Jobs;

use App\Services\Bot\BotEntry;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/** Avança a conversa do bot com a mensagem do cliente. Uma execução por ticket por vez (lock), na fila de entrada. */
class AdvanceBotSessionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 6;

    /** O bloco Esperar pode segurar a execução por até 30 s; várias esperas somam. */
    public int $timeout = 120;

    public function __construct(public readonly int $ticketId, public readonly int $messageId)
    {
        $this->onQueue('inbound');
    }

    public function handle(BotEntry $entry): void
    {
        $lock = Cache::lock('bot-ticket-'.$this->ticketId, 120);

        // Outra execução do mesmo ticket em andamento: espera a vez em vez de rodar em paralelo.
        if (! $lock->get()) {
            $this->release(3);

            return;
        }

        try {
            $entry->advance($this->ticketId, $this->messageId);
        } finally {
            $lock->release();
        }
    }
}
