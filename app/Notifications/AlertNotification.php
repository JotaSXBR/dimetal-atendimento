<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Base dos alertas guardados no sino (banco) e entregues na hora (broadcast). O payload leva só ids, `kind` e `level`:
 * texto de cliente nunca entra aqui. A tela e o módulo de som decidem o que mostrar a partir do `kind`.
 */
abstract class AlertNotification extends Notification
{
    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /** @return array<string, int|string> */
    abstract public function toArray(object $notifiable): array;
}
