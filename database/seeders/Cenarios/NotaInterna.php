<?php

namespace Database\Seeders\Cenarios;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketNote;
use Illuminate\Database\Seeder;

class NotaInterna extends Seeder
{
    public function run(): void
    {
        $ticket = Ticket::factory()->create(['channel_id' => CanalConectado::canal()->id, 'status' => TicketStatus::AGUARDANDO]);

        TicketNote::factory()->create(['ticket_id' => $ticket->id, 'user_id' => UsuariosDeTeste::admin()->id, 'body' => 'Cliente pediu retorno após as 14h.']);
    }
}
