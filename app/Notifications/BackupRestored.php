<?php

namespace App\Notifications;

/** O banco foi restaurado (ou o anterior foi reativado). Sem texto livre: os detalhes ficam em `BackupStatus::lastRestore()`. */
final class BackupRestored extends AlertNotification
{
    public function toArray(object $notifiable): array
    {
        return ['kind' => 'backup', 'level' => 'info', 'state' => 'restored'];
    }
}
