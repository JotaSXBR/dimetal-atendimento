<?php

namespace App\Policies;

use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Models\Message;
use App\Models\User;

/**
 * O menu da bolha. Tudo exige poder responder ao ticket. Reagir vale para qualquer mensagem já enviada; editar e apagar só
 * para a mensagem do painel e só para quem a escreveu (nem supervisor nem admin mexem na de outro).
 */
class MessagePolicy
{
    public function reply(User $user, Message $message): bool
    {
        $ticket = $message->ticket;

        return $ticket !== null && $user->can('reply', $ticket);
    }

    public function react(User $user, Message $message): bool
    {
        return $message->wa_message_id !== null && $message->revoked_at === null && $this->reply($user, $message);
    }

    /** Texto do painel, do próprio atendente, dentro da janela de edição do WhatsApp. */
    public function edit(User $user, Message $message): bool
    {
        return $message->type === 'text'
            && $message->sent_at->gte(now()->subMinutes(Message::EDIT_WINDOW_MINUTES))
            && $this->own($user, $message);
    }

    /** Sem janela nossa: o WhatsApp decide e a recusa mostra o motivo. */
    public function revoke(User $user, Message $message): bool
    {
        return $this->own($user, $message);
    }

    private function own(User $user, Message $message): bool
    {
        return $message->direction === MessageDirection::OUT
            && $message->origin === MessageOrigin::PAINEL
            && $message->author_user_id === $user->id
            && $this->react($user, $message);
    }
}
