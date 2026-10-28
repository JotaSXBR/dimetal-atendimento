<?php

namespace App\Services\Inbox;

use App\Enums\DeliveryStatus;
use App\Events\MessageStatusChanged;
use App\Models\Message;
use App\Models\WahaEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
            $message = $this->find($event->channel_id, $waId);

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

    /**
     * O ack e a mensagem podem citar o mesmo id com prefixos de chat diferentes (`@c.us` x `@lid`); o sufixo
     * após o último `_` é o id da mensagem no WhatsApp. Casa exato primeiro e cai para o sufixo.
     *
     * shortcut: o fallback varre as mensagens de 7 dias do canal, sem índice; indexar o sufixo se o volume pedir.
     */
    private function find(?int $channelId, string $waId): ?Message
    {
        $base = Message::query()->where('channel_id', $channelId)->lockForUpdate();

        $message = (clone $base)->where('wa_message_id', $waId)->first();
        $suffix = Str::afterLast($waId, '_');

        if ($message !== null || ! preg_match('/^[A-Za-z0-9]{10,}$/', $suffix)) {
            return $message;
        }

        return $base
            ->where('wa_message_id', 'like', '%\_'.$suffix)
            ->where('created_at', '>=', now()->subDays(7))
            ->first();
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
