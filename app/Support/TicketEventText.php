<?php

namespace App\Support;

use App\Enums\CloseOutcome;
use App\Models\TicketEvent;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;

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
        'sla_breached' => 'SLA estourado',
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

        if ($event->type === 'sla_breached') {
            return self::slaDetail($payload);
        }

        return $payload === [] ? '' : (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    /**
     * "esperando desde 30/10 10:00, limite 30/10 10:10" no fuso de exibição; vazio se o payload não traz as duas datas válidas.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function slaDetail(array $payload): string
    {
        $since = self::localTime($payload['waiting_since'] ?? null);
        $due = self::localTime($payload['due_at'] ?? null);

        return $since === null || $due === null ? '' : "esperando desde {$since}, limite {$due}";
    }

    private static function localTime(mixed $iso): ?string
    {
        if (! is_string($iso)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($iso)->setTimezone(config()->string('app.display_timezone'))->format('d/m H:i');
        } catch (InvalidFormatException) {
            return null;
        }
    }
}
