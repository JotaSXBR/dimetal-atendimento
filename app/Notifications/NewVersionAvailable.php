<?php

namespace App\Notifications;

/** Saiu uma versão nova do Fluxie Inbox (aviso aos admins, uma vez por versão). */
final class NewVersionAvailable extends AlertNotification
{
    public function __construct(private readonly string $version) {}

    public function toArray(object $notifiable): array
    {
        return ['kind' => 'update', 'level' => 'info', 'state' => 'available', 'version' => $this->version];
    }
}
