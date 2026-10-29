<?php

namespace App\Services\Inbox;

use App\Enums\ChannelStatus;
use App\Enums\DeliveryStatus;
use App\Events\MessageStatusChanged;
use App\Models\Channel;
use App\Models\Message;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Esvazia a caixa de saída de um canal: envia, em ordem, o que ficou aguardando a conexão do WhatsApp.
 *
 * Roda na fila `outbound` (um processo), então não concorre com os envios novos. Para na primeira mensagem que voltar a
 * esperar (a sessão caiu de novo: as seguintes também esperariam) e segue quando uma é recusada com motivo (isso é da
 * conversa, não do canal). O que esperou mais de 24 h vira falha, para o atendente decidir.
 */
class DrainOutbox
{
    /** Mensagens por rodada: cada envio leva segundos (presença) e o job tem 110 s. Sobrando, a rotina se agenda de novo. */
    public const BATCH = 10;

    public const EXPIRES_AFTER_HOURS = 24;

    /** Depois disto uma rodada não começa novos envios (sobrando mensagens, o job se agenda de novo). */
    public const TIME_BUDGET_SECONDS = 10;

    public function __construct(private readonly SendTicketMessage $send) {}

    /**
     * @return bool `true` quando ainda sobram mensagens para esvaziar (o chamador agenda outra rodada)
     */
    public function __invoke(Channel $channel): bool
    {
        $this->expire($channel);

        if ($channel->refresh()->status !== ChannelStatus::WORKING) {
            return false;
        }

        $waiting = $this->waiting($channel)->limit(self::BATCH)->get();
        $started = now();

        foreach ($waiting as $position => $message) {
            // Um anexo pode levar até 90 s para subir: depois de 10 s de trabalho não se começa outro envio. Se o
            // worker matasse o job no meio de um envio, a linha ainda estaria aguardando e o cliente receberia o arquivo duas vezes.
            if ($position > 0 && $started->diffInSeconds(now()) > self::TIME_BUDGET_SECONDS) {
                return true;
            }

            try {
                $this->send->deliver($message);
            } catch (Throwable) {
                // A falha já ficou gravada na linha, com o motivo; as outras conversas seguem.
            }

            if ($message->refresh()->delivery_status === DeliveryStatus::QUEUED) {
                return false;
            }
        }

        return $this->waiting($channel)->exists();
    }

    /** O canal consta conectado e ainda há mensagem aguardando: o envio estacionou (sessão sincronizando). */
    public function stuckWhileConnected(Channel $channel): bool
    {
        return $channel->refresh()->status === ChannelStatus::WORKING && $this->waiting($channel)->exists();
    }

    /** Falha as que esperaram demais (ou todas, quando o canal é omitido). */
    public function expire(?Channel $channel = null): void
    {
        Message::query()
            ->where('delivery_status', DeliveryStatus::QUEUED->value)
            ->where('created_at', '<', now()->subHours(self::EXPIRES_AFTER_HOURS))
            ->when($channel !== null, fn ($q) => $q->where('channel_id', $channel?->id))
            ->each(function (Message $message): void {
                $message->update([
                    'delivery_status' => DeliveryStatus::FAILED,
                    'failure_code' => SendFailure::EXPIRED,
                    'failure_reason' => SendFailure::reason(SendFailure::EXPIRED, new \RuntimeException),
                ]);
                MessageStatusChanged::dispatch($message);
            });
    }

    /** @return Builder<Message> */
    private function waiting(Channel $channel)
    {
        return Message::query()
            ->where('channel_id', $channel->id)
            ->where('delivery_status', DeliveryStatus::QUEUED->value)
            ->orderBy('id');
    }
}
