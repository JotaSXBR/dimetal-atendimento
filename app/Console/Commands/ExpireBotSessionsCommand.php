<?php

namespace App\Console\Commands;

use App\Models\BotSession;
use App\Services\Bot\BotEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ExpireBotSessionsCommand extends Command
{
    protected $signature = 'app:expire-bot-sessions';

    protected $description = 'Encaminha à fila padrão os clientes que ficaram sem responder ao bot além do prazo';

    public function handle(BotEngine $engine): int
    {
        $done = 0;

        BotSession::query()->active()->where('expires_at', '<=', now())->orderBy('id')->limit(200)->each(function (BotSession $session) use ($engine, &$done): void {
            // Não corre contra uma resposta que o job do bot está processando agora.
            $lock = Cache::lock('bot-ticket-'.$session->ticket_id, 120);

            if (! $lock->get()) {
                return;
            }

            try {
                $engine->handoff($session, 'timeout');
                $done++;
            } finally {
                $lock->release();
            }
        });

        $this->info("Sessões encaminhadas por silêncio: {$done}");

        return self::SUCCESS;
    }
}
