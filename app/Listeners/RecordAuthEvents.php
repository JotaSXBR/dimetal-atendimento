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

    /**
     * O evento traz a senha digitada em `credentials`: só o e-mail vai para a auditoria. E só se for mesmo um e-mail: quem cola a
     * senha no campo do e-mail não pode deixar a senha gravada por um ano (e à vista do admin).
     */
    public function handleFailed(Failed $event): void
    {
        $typed = $event->credentials['email'] ?? '';
        $email = is_string($typed) ? trim($typed) : '';

        $subject = match (true) {
            $email === '' => '(vazio)',
            filter_var($email, FILTER_VALIDATE_EMAIL) === false => '(valor que não é e-mail)',
            default => $email,
        };

        Auditor::record(AuditAction::LoginFailed, null, $subject);
    }

    public function handleLogout(Logout $event): void
    {
        Auditor::record(AuditAction::Logout, $event->user instanceof User ? $event->user : null);
    }
}
