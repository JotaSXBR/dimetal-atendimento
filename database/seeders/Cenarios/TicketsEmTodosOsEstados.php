<?php

namespace Database\Seeders\Cenarios;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Database\Seeders\SoRodaEmAmbienteDeTeste;
use Illuminate\Database\Seeder;

class TicketsEmTodosOsEstados extends Seeder
{
    use SoRodaEmAmbienteDeTeste;

    public function run(): void
    {
        $this->exigirAmbienteDeTeste();

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
