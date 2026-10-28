<?php

namespace App\Services\Inbox;

use App\Enums\DeliveryStatus;
use App\Events\MessageStatusChanged;
use App\Models\Message;
use App\Models\WahaEvent;
use Illuminate\Support\Facades\DB;

/**
 * Aplica um `message.ack` do WAHA na mensagem correspondente.
 *
 * Códigos do WAHA: 1 enviado, 2 entregue, 3 lido, 4 reproduzido, -1 falhou; 0 e demais são ignorados.
 * O estado só avança: um ack atrasado nunca rebaixa `read` para `delivered`.
 */
class ApplyAck
{
    /**
     * @return bool `false` quando a mensagem ainda não existe (o job tenta de novo); `true` nos demais casos
     */
    public function __invoke(WahaEvent $event): bool
    {
        $data = $event->payload['payload'] ?? null;
        $waId = is_array($data) ? ($data['id'] ?? null) : null;
        $status = is_array($data) ? $this->statusFor($data['ack'] ?? null) : null;

        if ($event->channel === null || ! is_string($waId) || $status === null) {
            return true;
        }

        // Entregue e lido chegam quase juntos e em workers diferentes: a linha é lida com trava para que
        // o job que leu "entregue" não grave por cima de "lido".
        return DB::transaction(function () use ($event, $waId, $status): bool {
            $message = Message::query()
                ->where('channel_id', $event->channel_id)
                ->where('wa_message_id', $waId)
                ->lockForUpdate()
                ->first();

            if ($message === null) {
                return false;
            }

            $current = $message->delivery_status?->rank() ?? 0;

            if ($status === DeliveryStatus::FAILED ? $current > 1 : $status->rank() <= $current) {
                return true;
            }

            $message->delivery_status = $status;

            if ($status->rank() >= DeliveryStatus::DELIVERED->rank()) {
                $message->delivered_at ??= $event->received_at;
            }

            if ($status === DeliveryStatus::READ) {
                $message->read_at ??= $event->received_at;
            }

            $message->save();

            MessageStatusChanged::dispatch($message);

            return true;
        });
    }

    private function statusFor(mixed $code): ?DeliveryStatus
    {
        return match (is_numeric($code) ? (int) $code : null) {
            -1 => DeliveryStatus::FAILED,
            1 => DeliveryStatus::SENT,
            2 => DeliveryStatus::DELIVERED,
            3, 4 => DeliveryStatus::READ,
            default => null,
        };
    }
}
