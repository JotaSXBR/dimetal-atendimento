<?php

namespace Database\Seeders\Cenarios;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Notifications\MessagesRecovered;
use App\Notifications\SlaBreached;
use Illuminate\Database\Seeder;

class AlertasDoSino extends Seeder
{
    public function run(): void
    {
        $admin = UsuariosDeTeste::admin();
        $canal = CanalConectado::canal();

        foreach ([TicketStatus::AGUARDANDO, TicketStatus::ENCERRADO] as $status) {
            $ticket = Ticket::factory()->create(['channel_id' => $canal->id, 'status' => $status, 'closed_at' => $status === TicketStatus::ENCERRADO ? now() : null]);
            $admin->notify(new SlaBreached($ticket));
        }

        $admin->notify(new MessagesRecovered($canal, 0, 0));
    }
}
