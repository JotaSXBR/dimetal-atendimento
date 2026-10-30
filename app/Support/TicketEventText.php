<?php

namespace App\Support;

use App\Enums\CloseOutcome;
use App\Models\TicketEvent;

/** Como um evento do ticket aparece em texto: o rótulo do tipo e o detalhe. Usado na auditoria e no histórico. */
final class TicketEventText
{
    public const TYPES = [
        'opened' => 'Ticket aberto',
        'claimed' => 'Assumido',
        'closed' => 'Encerrado',
        'transferred' => 'Transferido',
        'reassigned' => 'Reatribuído',
        'taken_over' => 'Tomado',
        'merged' => 'Mesclado',
        'message_out' => 'Mensagem enviada',
        'after_hours_reply' => 'Resposta fora do horário',
        'media_purged' => 'Mídia apagada',
    ];

    public static function label(string $type): string
    {
        return self::TYPES[$type] ?? $type;
    }

    /** O que aconteceu, em texto: motivo e resumo do encerramento; nos demais, o conteúdo bruto do evento. */
    public static function detail(TicketEvent $event): string
    {
        $payload = $event->payload ?? [];

        if ($event->type === 'closed') {
            $outcome = is_string($payload['outcome'] ?? null) ? CloseOutcome::tryFrom($payload['outcome'])?->label() : null;
            $reason = isset($payload['outcome']) || ! is_string($payload['reason'] ?? null) ? null : $payload['reason'];
            $summary = is_string($payload['summary'] ?? null) ? $payload['summary'] : null;

            return implode(' · ', array_filter([$outcome, $summary, $reason]));
        }

        return $payload === [] ? '' : (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
}
