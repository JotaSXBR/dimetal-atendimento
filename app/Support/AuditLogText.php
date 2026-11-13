<?php

namespace App\Support;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Models\AuditLog;

/** Uma linha da auditoria do sistema em frase. Só usa campos conhecidos do `detail`: o resto nunca vira texto. */
final class AuditLogText
{
    private const USER_FIELDS = ['name' => 'nome', 'email' => 'e-mail', 'can_transfer' => 'permissão de transferir', 'sectors' => 'setores'];

    public static function sentence(AuditLog $log): string
    {
        $action = AuditAction::tryFrom($log->action);

        if ($action === null) {
            return $log->action;
        }

        $who = $log->actor_label ?? 'Sistema';
        $subject = $log->subject ?? '';

        return match ($action) {
            AuditAction::Login => "{$who} entrou no sistema",
            AuditAction::LoginFailed => "Login recusado para {$subject}",
            AuditAction::Logout => "{$who} saiu do sistema",
            AuditAction::UserCreated => trim("{$who} criou o usuário {$subject} ".self::role($log, 'role', '(', ')')),
            AuditAction::UserUpdated => trim("{$who} alterou o usuário {$subject} ".self::fields($log)),
            AuditAction::UserRoleChanged => "{$who} mudou o papel de {$subject}: ".(self::roleLabel($log, 'from') ?? '?').' → '.(self::roleLabel($log, 'to') ?? '?'),
            AuditAction::UserPasswordSet => "{$who} definiu uma nova senha para {$subject}",
            AuditAction::UserDeactivated => "{$who} desativou o usuário {$subject}",
            AuditAction::UserActivated => "{$who} reativou o usuário {$subject}",
            AuditAction::BackupDownloaded => "{$who} baixou o backup {$subject}",
            AuditAction::BackupManualRequested => "{$who} pediu um backup manual",
            AuditAction::RestoreRequested => "{$who} pediu a restauração de {$subject}",
            AuditAction::RestoreSwitched => "{$who} pediu para voltar ao banco guardado {$subject}",
            AuditAction::RestoreSucceeded => trim("Restauração concluída ({$subject}), pedida por {$who}. ".self::message($log)),
            AuditAction::RestoreFailed => trim("Restauração falhou ({$subject}), pedida por {$who}. ".self::message($log)),
            AuditAction::GoogleConnected => "{$who} conectou a conta Google {$subject}",
            AuditAction::GoogleDisconnected => "{$who} desconectou a conta Google",
            AuditAction::ChannelCreated => "{$who} criou o canal {$subject}",
            AuditAction::ChannelDeleted => "{$who} apagou o canal {$subject}",
            AuditAction::RetentionPurgeRequested => "{$who} pediu o expurgo de anexos antigos",
        };
    }

    private static function roleLabel(AuditLog $log, string $key): ?string
    {
        $value = $log->detail[$key] ?? null;

        return is_string($value) ? UserRole::tryFrom($value)?->label() : null;
    }

    private static function role(AuditLog $log, string $key, string $open, string $close): string
    {
        $label = self::roleLabel($log, $key);

        return $label === null ? '' : $open.$label.$close;
    }

    /** "(nome, setores)": só os campos que a tela conhece. */
    private static function fields(AuditLog $log): string
    {
        $fields = $log->detail['fields'] ?? null;
        $names = [];

        foreach (is_array($fields) ? $fields : [] as $field) {
            if (is_string($field) && isset(self::USER_FIELDS[$field])) {
                $names[] = self::USER_FIELDS[$field];
            }
        }

        return $names === [] ? '' : '('.implode(', ', $names).')';
    }

    private static function message(AuditLog $log): string
    {
        $message = $log->detail['message'] ?? null;

        return is_string($message) ? $message : '';
    }
}
