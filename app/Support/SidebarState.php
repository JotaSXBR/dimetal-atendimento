<?php

namespace App\Support;

use App\Enums\ChannelStatus;
use App\Enums\TicketStatus;
use App\Models\Channel;
use App\Models\Ticket;
use App\Models\User;

/** O que o menu lateral mostra além dos links: pendências do usuário e se o WhatsApp está fora do ar. */
class SidebarState
{
    /** @return array{waiting: int, breached: int} */
    public function counts(User $user): array
    {
        $visible = fn () => Ticket::query()->open()->visibleTo($user);

        return [
            'waiting' => $visible()->where('status', TicketStatus::AGUARDANDO->value)->count(),
            'breached' => $visible()->whereNotNull('waiting_since')->where('sla_due_at', '<=', now())->count(),
        ];
    }

    /** Há canal cadastrado e nenhum está conectado. */
    public function whatsappDown(): bool
    {
        // Canal desativado de propósito não é queda: só os ativos contam.
        $active = fn () => Channel::query()->where('active', true);

        return $active()->exists() && ! $active()->where('status', ChannelStatus::WORKING->value)->exists();
    }
}
