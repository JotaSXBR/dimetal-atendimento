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
        $data = is_array($data) ? $data : [];
        $waId = is_string($data['id'] ?? null) ? Message::waKey($data['id']) : null;
        $status = $this->statusFor($data['ack'] ?? null);

        if ($event->channel === null || $waId === null || $status === null) {
            return true;
        }

        // Entregue e lido chegam quase juntos e em workers diferentes: a linha é lida com trava para que
        // o job que leu "entregue" não grave por cima de "lido".
        return DB::transaction(fn (): bool => $this->apply($event, $waId, $status));
    }

    private function apply(WahaEvent $event, string $waId, DeliveryStatus $status): bool
    {
        $message = Message::query()
            ->where('channel_id', $event->channel_id)
            ->where('wa_message_id', $waId)
            ->lockForUpdate()
            ->first();

        if ($message === null) {
            return false;
        }

        if (! $this->advances($message->delivery_status, $status)) {
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
    }

    /**
     * Só avança: falha não sobrescreve entregue/lido, e um estado igual ou anterior é ignorado.
     */
    private function advances(?DeliveryStatus $current, DeliveryStatus $new): bool
    {
        $rank = $current?->rank() ?? 0;

        return $new === DeliveryStatus::FAILED ? $rank <= 1 : $new->rank() > $rank;
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
