<?php

namespace Database\Seeders\Cenarios;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Illuminate\Database\Seeder;

class TicketsEmTodosOsEstados extends Seeder
{
    public function run(): void
    {
        $canal = CanalConectado::canal();
        $admin = UsuariosDeTeste::admin();

        foreach (TicketStatus::cases() as $status) {
            Ticket::factory()->create([
                'channel_id' => $canal->id,
                'status' => $status,
                'assigned_user_id' => $status === TicketStatus::EM_ATENDIMENTO ? $admin->id : null,
                'closed_at' => $status === TicketStatus::ENCERRADO ? now() : null,
            ]);
        }
    }
}
