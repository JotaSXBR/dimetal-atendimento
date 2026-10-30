<?php

namespace App\Services\Alerts;

use App\Enums\ChannelStatus;
use App\Events\ChannelStatusChanged;
use App\Events\TicketQueued;
use App\Models\Channel;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\ChannelDown;
use App\Notifications\ChannelUp;
use App\Notifications\SlaBreached;
use App\Notifications\SlaWarning;
use App\Notifications\TicketAssigned;
use Illuminate\Support\Facades\Notification;

/** O único lugar que transforma um fato (canal caiu, SLA estourou...) em alertas, usando {@see AlertRecipients}. */
final class AlertDispatcher
{
    public function __construct(private readonly AlertRecipients $recipients) {}

    /**
     * Só a travessia de "conectado" para "não conectado" (e de volta) alerta: ir de STARTING para SCAN_QR_CODE não repete o aviso.
     * A faixa se atualiza em qualquer mudança de estado.
     */
    public function channelChanged(Channel $channel, ChannelStatus $from): void
    {
        ChannelStatusChanged::dispatch($channel->id, $channel->status->value);

        if (! $channel->active) {
            return;
        }

        $wasUp = $from === ChannelStatus::WORKING;

        if ($wasUp && ! $channel->isConnected()) {
            Notification::send($this->recipients->everyone(), new ChannelDown($channel));
        } elseif (! $wasUp && $channel->isConnected()) {
            Notification::send($this->recipients->everyone(), new ChannelUp($channel));
        }
    }

    public function sla(Ticket $ticket, bool $breached): void
    {
        Notification::send($this->recipients->sla($ticket), $breached ? new SlaBreached($ticket) : new SlaWarning($ticket));
    }

    public function assigned(Ticket $ticket, User $to, User $actor): void
    {
        if ($to->id !== $actor->id) {
            $to->notify(new TicketAssigned($ticket));
        }
    }

    public function queued(Ticket $ticket, ?int $exceptUserId = null): void
    {
        $ids = $this->recipients->queue($ticket)->pluck('id')->reject(fn (int $id): bool => $id === $exceptUserId)->values()->all();

        if ($ids !== []) {
            TicketQueued::dispatch($ticket->id, $ids);
        }
    }
}
