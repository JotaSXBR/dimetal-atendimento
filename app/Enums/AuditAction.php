<?php

namespace App\Enums;

/** O que a auditoria do sistema registra. O valor é o que fica gravado em `audit_logs.action`. */
enum AuditAction: string
{
    case Login = 'auth.login';
    case LoginFailed = 'auth.login_failed';
    case Logout = 'auth.logout';
    case UserCreated = 'user.created';
    case UserUpdated = 'user.updated';
    case UserRoleChanged = 'user.role_changed';
    case UserPasswordSet = 'user.password_set';
    case UserDeactivated = 'user.deactivated';
    case UserActivated = 'user.activated';
    case BackupDownloaded = 'backup.downloaded';
    case BackupManualRequested = 'backup.manual_requested';
    case RestoreRequested = 'restore.requested';
    case RestoreSucceeded = 'restore.succeeded';
    case RestoreFailed = 'restore.failed';
    case RestoreSwitched = 'restore.switched';
    case GoogleConnected = 'google.connected';
    case GoogleDisconnected = 'google.disconnected';
    case ChannelCreated = 'channel.created';
    case ChannelDeleted = 'channel.deleted';
    case RetentionPurgeRequested = 'retention.purge_requested';

    public function label(): string
    {
        return match ($this) {
            self::Login => 'Login',
            self::LoginFailed => 'Login recusado',
            self::Logout => 'Saída',
            self::UserCreated => 'Usuário criado',
            self::UserUpdated => 'Usuário alterado',
            self::UserRoleChanged => 'Papel alterado',
            self::UserPasswordSet => 'Senha definida',
            self::UserDeactivated => 'Usuário desativado',
            self::UserActivated => 'Usuário reativado',
            self::BackupDownloaded => 'Backup baixado',
            self::BackupManualRequested => 'Backup manual pedido',
            self::RestoreRequested => 'Restauração pedida',
            self::RestoreSucceeded => 'Restauração concluída',
            self::RestoreFailed => 'Restauração falhou',
            self::RestoreSwitched => 'Volta ao banco guardado pedida',
            self::GoogleConnected => 'Google conectado',
            self::GoogleDisconnected => 'Google desconectado',
            self::ChannelCreated => 'Canal criado',
            self::ChannelDeleted => 'Canal apagado',
            self::RetentionPurgeRequested => 'Expurgo de anexos pedido',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::Login, self::LoginFailed, self::Logout => 'Acesso',
            self::UserCreated, self::UserUpdated, self::UserRoleChanged, self::UserPasswordSet, self::UserDeactivated, self::UserActivated => 'Usuários',
            self::BackupDownloaded, self::BackupManualRequested, self::RestoreRequested, self::RestoreSucceeded, self::RestoreFailed, self::RestoreSwitched => 'Backup',
            self::GoogleConnected, self::GoogleDisconnected, self::ChannelCreated, self::ChannelDeleted, self::RetentionPurgeRequested => 'Configurações',
        };
    }

    /** @return list<string> */
    public static function groups(): array
    {
        return ['Acesso', 'Usuários', 'Backup', 'Configurações'];
    }
}
