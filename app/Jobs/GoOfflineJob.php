<?php

namespace App\Jobs;

use App\Models\Channel;
use App\Services\Inbox\Presence;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Devolve a sessão a "offline" depois de um período sem envios, para o celular do atendente voltar a receber push.
 * Só o job do último envio age: os anteriores veem o token trocado e não fazem nada.
 */
class GoOfflineJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $channelId, public readonly string $token)
    {
        $this->onQueue('outbound');
    }

    public function handle(Presence $presence): void
    {
        $presence->goOffline(Channel::findOrFail($this->channelId), $this->token);
    }
}
