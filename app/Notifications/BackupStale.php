<?php

namespace App\Notifications;

/** Passou da hora sem backup bom do banco. */
final class BackupStale extends AlertNotification
{
    public function toArray(object $notifiable): array
    {
        return ['kind' => 'backup', 'level' => 'critico', 'state' => 'stale'];
    }
}
