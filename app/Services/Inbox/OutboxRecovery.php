<?php

namespace App\Services\Inbox;

use App\Enums\DeliveryStatus;
use App\Events\MessageStatusChanged;
use App\Models\Message;
use Illuminate\Database\Eloquent\Builder;

/**
 * Recolhe o envio que ficou "Enviando" sem ninguém cuidando dele (job que morreu ou sumiu da fila). Pode ter saído, então
 * vira falha "sem resposta": o atendente confere no celular do cliente e só então reenvia.
 */
class OutboxRecovery
{
    /** Um envio normal leva segundos; passado isto, ninguém mais está cuidando da linha. */
    public const STUCK_AFTER_MINUTES = 10;

    /**
     * Marca como "sem resposta" as linhas do painel que ainda não têm id nem estado.
     *
     * @param  Builder<Message>  $query
     */
    public function markUnknown(Builder $query): void
    {
        $query->whereNull('wa_message_id')->whereNull('delivery_status')->each(function (Message $message): void {
            $message->update([
                'delivery_status' => DeliveryStatus::FAILED,
                'failure_code' => SendFailure::UNKNOWN,
                'failure_reason' => SendFailure::reason(SendFailure::UNKNOWN, new \RuntimeException),
            ]);
            MessageStatusChanged::dispatch($message);
        });
    }

    public function recoverStuck(): void
    {
        $this->markUnknown(Message::query()
            ->where('direction', 'out')
            ->where('origin', 'painel')
            ->where('updated_at', '<', now()->subMinutes(self::STUCK_AFTER_MINUTES)));
    }
}
