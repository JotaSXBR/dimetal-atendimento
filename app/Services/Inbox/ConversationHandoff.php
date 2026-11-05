<?php

namespace App\Services\Inbox;

use App\Models\Ticket;
use App\Models\User;

/**
 * Recado da janela "Nova conversa" para o Inbox quando o contato já tinha conversa aberta: o aviso de quem é o ticket e, se o
 * usuário pode responder, o texto que ele digitou (que vai ao campo de resposta, sem ser enviado). Viaja na sessão (um redirect).
 */
final class ConversationHandoff
{
    private const NOTICE = 'inbox-notice';

    private const DRAFT = 'inbox-draft';

    public static function flash(string $notice, string $draft, bool $canReply): void
    {
        session()->flash(self::NOTICE, $notice);

        if ($canReply && $draft !== '') {
            session()->flash(self::DRAFT, $draft);
        }
    }

    /** @return array{notice: ?string, draft: ?string} o texto só sai se o usuário ainda pode responder o ticket */
    public static function pull(User $user, Ticket $ticket): array
    {
        $notice = session()->pull(self::NOTICE);
        $draft = session()->pull(self::DRAFT);

        return [
            'notice' => is_string($notice) ? $notice : null,
            'draft' => is_string($draft) && $user->can('reply', $ticket) ? $draft : null,
        ];
    }
}
