<?php

namespace App\Listeners;

use App\Enums\AuditAction;
use App\Models\User;
use App\Services\Audit\Auditor;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/** Login, falha de login e saída na auditoria do sistema. O Laravel descobre os métodos `handle*` pelo tipo do evento. */
class RecordAuthEvents
{
    public function handleLogin(Login $event): void
    {
        Auditor::record(AuditAction::Login, $event->user instanceof User ? $event->user : null);
    }

    /** O evento traz a senha digitada em `credentials`: só o e-mail vai para a auditoria. */
    public function handleFailed(Failed $event): void
    {
        $email = $event->credentials['email'] ?? '';

        Auditor::record(AuditAction::LoginFailed, null, is_string($email) ? $email : '');
    }

    public function handleLogout(Logout $event): void
    {
        Auditor::record(AuditAction::Logout, $event->user instanceof User ? $event->user : null);
    }
}
