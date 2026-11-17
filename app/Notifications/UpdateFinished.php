<?php

namespace App\Notifications;

/** Uma atualização do sistema terminou (`concluida`, `revertida` ou `falhou`): aviso aos admins, uma vez por atualização e estado final. */
final class UpdateFinished extends AlertNotification
{
    public function __construct(private readonly string $state, private readonly string $version, private readonly int $updateId) {}

    public function toArray(object $notifiable): array
    {
        return ['update_id' => $this->updateId, 'kind' => 'update', 'level' => $this->state === 'concluida' ? 'info' : 'danger', 'state' => $this->state, 'version' => $this->version];
    }
}
