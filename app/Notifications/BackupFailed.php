<?php

namespace App\Notifications;

/** O backup do banco falhou. Sem texto livre: o motivo fica em `BackupStatus::lastError()` e na tela de Backup. */
final class BackupFailed extends AlertNotification
{
    public function toArray(object $notifiable): array
    {
        return ['kind' => 'backup', 'level' => 'critico', 'state' => 'failed'];
    }
}
