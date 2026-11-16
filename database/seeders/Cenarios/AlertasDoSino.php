<?php

namespace Database\Seeders\Cenarios;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Notifications\MessagesRecovered;
use App\Notifications\SlaBreached;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Notification;

class AlertasDoSino extends Seeder
{
    public function run(): void
    {
        $admin = UsuariosDeTeste::admin();
        $canal = CanalConectado::canal();

        foreach ([TicketStatus::AGUARDANDO, TicketStatus::ENCERRADO] as $status) {
            $ticket = Ticket::factory()->create(['channel_id' => $canal->id, 'status' => $status, 'closed_at' => $status === TicketStatus::ENCERRADO ? now() : null]);
            Notification::sendNow($admin, new SlaBreached($ticket), ['database']); // sem broadcast: não empurra para o Reverb
        }

        Notification::sendNow($admin, new MessagesRecovered($canal, 0, 0), ['database']);
    }
}
