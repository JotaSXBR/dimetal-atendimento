<?php

namespace App\Services\Bot\Nodes;

use App\Services\Bot\BotRun;
use App\Services\Bot\Step;
use App\Services\Waha\WahaClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/** Pausa de 1 a 30 s mostrando "digitando…" ao cliente. A presença é melhor esforço: se o WAHA falhar, só a pausa fica. */
final class EsperarNode extends Node
{
    public const MIN_SECONDS = 1;

    public const MAX_SECONDS = 30;

    public function enter(BotRun $run, array $data): Step
    {
        $seconds = max(self::MIN_SECONDS, min(self::MAX_SECONDS, (int) ($data['segundos'] ?? self::MIN_SECONDS)));
        $contact = $run->ticket->contact()->firstOrFail();
        $chatId = $contact->wa_id ?? (string) $contact->lid;
        $client = new WahaClient($run->ticket->channel()->firstOrFail());

        try {
            $client->startTyping($chatId);
        } catch (Throwable $e) {
            Log::warning('Bot: "digitando" falhou e foi ignorado', ['erro' => $e->getMessage()]);
        }

        Sleep::for($seconds)->seconds();

        try {
            $client->stopTyping($chatId);
        } catch (Throwable $e) {
            Log::warning('Bot: parar "digitando" falhou e foi ignorado', ['erro' => $e->getMessage()]);
        }

        return Step::next();
    }
}
