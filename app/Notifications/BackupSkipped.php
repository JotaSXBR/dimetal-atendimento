<?php

namespace App\Notifications;

/** O backup diário não rodou porque outro estava em andamento (trava ocupada). */
final class BackupSkipped extends AlertNotification
{
    public function toArray(object $notifiable): array
    {
        return ['kind' => 'backup', 'level' => 'grave', 'state' => 'skipped'];
    }
}
