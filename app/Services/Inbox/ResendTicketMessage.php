<?php

namespace App\Services\Inbox;

use App\Enums\DeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Jobs\SendTicketMessageJob;
use App\Models\Message;
use App\Models\User;

/**
 * Reenvia, na MESMA linha, uma mensagem do painel que falhou. Uma linha nova desenharia uma segunda bolha e, se a primeira
 * tiver saído, duplicaria a mensagem para o cliente.
 *
 * A regra de quem pode reenviar e se pede confirmação é uma só (`kind`), lida pela tela e pelo servidor: assim a tela nunca
 * oferece um botão que o servidor recusa.
 */
class ResendTicketMessage
{
    public const SAFE = 'safe';

    public const CONFIRM = 'confirm';

    public const NONE = 'none';

    public function kind(Message $message, User $by): string
    {
        $mine = $message->author_user_id === $by->id || $by->canSeeAllSectors();

        $eligible = $message->delivery_status === DeliveryStatus::FAILED
            && $message->wa_message_id === null
            && $message->direction === MessageDirection::OUT
            && $message->origin === MessageOrigin::PAINEL
            && ($message->type === 'text' || ($message->type === 'media' && $message->local_path !== null))
            && $mine;

        return $eligible ? SendFailure::resend($message->failure_code) : self::NONE;
    }

    /**
     * @return string `sent` quando voltou para a fila de saída; `confirm` quando falta a confirmação; `none` quando não dá
     *                (ou já foi reenviada por outro toque)
     */
    public function __invoke(Message $message, User $by, bool $confirmed): string
    {
        $message->refresh();
        $kind = $this->kind($message, $by);

        if ($kind === self::NONE) {
            return self::NONE;
        }

        if ($kind === self::CONFIRM && ! $confirmed) {
            return self::CONFIRM;
        }

        // Trava por `updated_at`: o UPDATE só pega se a linha ainda está como foi lida, então dois toques enviam uma vez.
        $locked = Message::query()
            ->whereKey($message->id)
            ->where('delivery_status', DeliveryStatus::FAILED->value)
            ->where('updated_at', $message->updated_at)
            ->update(['delivery_status' => null, 'failure_code' => null, 'failure_reason' => null, 'updated_at' => now()]);

        if ($locked !== 1) {
            return self::NONE;
        }

        SendTicketMessageJob::dispatch($message->id);

        return 'sent';
    }
}
