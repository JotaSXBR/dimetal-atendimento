<?php

namespace App\Services\Audit;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Grava uma linha da auditoria do sistema. Nunca lança: um problema de auditoria não pode trancar o login nem a restauração do banco
 * (custo: o evento some e só o log avisa). O `$detail` nunca leva senha nem token.
 */
final class Auditor
{
    /**
     * @param  array<string, mixed>  $detail
     * @param  string|null  $actorLabel  para quem não tem usuário (falha de login) ou roda fora de um request (o job da restauração)
     */
    public static function record(AuditAction $action, ?User $actor = null, ?string $subject = null, array $detail = [], ?string $actorLabel = null): void
    {
        try {
            AuditLog::query()->create([
                'actor_user_id' => $actor?->id,
                'actor_label' => self::limit($actorLabel ?? ($actor === null ? null : $actor->name.' <'.$actor->email.'>')),
                'action' => $action->value,
                'subject' => self::limit($subject),
                'ip' => request()->ip(),
                'detail' => $detail === [] ? null : $detail,
            ]);
        } catch (Throwable $e) {
            Log::warning('Auditoria: registro não gravado', ['action' => $action->value, 'erro' => $e->getMessage()]);
        }
    }

    private static function limit(?string $text): ?string
    {
        return $text === null ? null : mb_substr($text, 0, 255);
    }
}
