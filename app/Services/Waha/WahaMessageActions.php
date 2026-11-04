<?php

namespace App\Services\Waha;

use App\Services\Inbox\MessageActionFailed;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/**
 * Reagir, editar e apagar uma mensagem no WhatsApp. O id é o completo (`{fromMe}_{chat}_{id}`); o chat e o id vão
 * codificados na rota. Qualquer recusa vira `MessageActionFailed` com o motivo para a tela.
 */
class WahaMessageActions
{
    public function __construct(private readonly WahaClient $client) {}

    /** Emoji vazio remove a reação. */
    public function react(string $fullId, string $emoji): void
    {
        $this->run(fn (): Response => $this->client->http()->put('/api/reaction', [
            'session' => $this->client->sessionName(),
            'messageId' => $fullId,
            'reaction' => $emoji,
        ]));
    }

    public function edit(string $chatId, string $fullId, string $text): void
    {
        $this->run(fn (): Response => $this->client->http()->put($this->path($chatId, $fullId), ['text' => $text]));
    }

    public function revoke(string $chatId, string $fullId): void
    {
        $this->run(fn (): Response => $this->client->http()->delete($this->path($chatId, $fullId)));
    }

    private function path(string $chatId, string $fullId): string
    {
        return '/api/'.$this->client->sessionName().'/chats/'.rawurlencode($chatId).'/messages/'.rawurlencode($fullId);
    }

    /** @param  Closure(): Response  $request */
    private function run(Closure $request): void
    {
        try {
            $this->client->check($request());
        } catch (WahaRequestException $e) {
            throw MessageActionFailed::fromWaha($e);
        } catch (ConnectionException) {
            throw MessageActionFailed::unreachable();
        }
    }
}
