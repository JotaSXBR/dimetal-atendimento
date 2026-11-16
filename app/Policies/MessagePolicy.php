<?php

namespace App\Policies;

use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Models\Message;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * O menu da bolha. Tudo exige poder responder ao ticket. Reagir vale para qualquer mensagem já enviada; editar e apagar só
 * para a mensagem do painel e só para quem a escreveu (nem supervisor nem admin mexem na de outro).
 */
class MessagePolicy
{
    /** O código da negação por prazo: a tela mostra o texto em vez de 403. */
    public const DEADLINE = 'prazo';

    public function reply(User $user, Message $message): bool
    {
        $ticket = $message->ticket;

        return $ticket !== null && $user->can('reply', $ticket);
    }

    public function react(User $user, Message $message): bool
    {
        return $message->wa_message_id !== null && $message->revoked_at === null && $this->reply($user, $message);
    }

    /** Texto do painel, do próprio atendente, dentro da janela de edição do WhatsApp (15 min). */
    public function edit(User $user, Message $message): Response
    {
        if ($message->type !== 'text' || ! $this->own($user, $message)) {
            return Response::deny();
        }

        return $message->sent_at->gte(now()->subMinutes(Message::EDIT_WINDOW_MINUTES))
            ? Response::allow()
            : Response::deny('O WhatsApp só permite editar até '.Message::EDIT_WINDOW_MINUTES.' minutos depois do envio.', self::DEADLINE);
    }

    /** A própria mensagem do painel, até 48 h depois do envio (prazo do WhatsApp para apagar para todos). */
    public function revoke(User $user, Message $message): Response
    {
        if (! $this->own($user, $message)) {
            return Response::deny();
        }

        $dias = intdiv(Message::REVOKE_WINDOW_HOURS, 24);

        return $message->sent_at->gte(now()->subHours(Message::REVOKE_WINDOW_HOURS))
            ? Response::allow()
            : Response::deny('O WhatsApp só permite apagar para todos até '.$dias.($dias === 1 ? ' dia' : ' dias').' depois do envio.', self::DEADLINE);
    }

    private function own(User $user, Message $message): bool
    {
        return $message->direction === MessageDirection::OUT
            && $message->origin === MessageOrigin::PAINEL
            && $message->author_user_id === $user->id
            && $this->react($user, $message);
    }
}
