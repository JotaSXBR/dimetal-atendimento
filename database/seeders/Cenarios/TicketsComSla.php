<?php

namespace Database\Seeders\Cenarios;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use Database\Seeders\SoRodaEmAmbienteDeTeste;
use Illuminate\Database\Seeder;

/** Dois tickets aguardando para o cabeçalho mostrar o SLA: um com o prazo estourado e outro a vencer. */
class TicketsComSla extends Seeder
{
    use SoRodaEmAmbienteDeTeste;

    public function run(): void
    {
        $this->exigirAmbienteDeTeste();

        $canal = CanalConectado::canal();

        foreach ([[-90, -30], [-5, 25]] as [$espera, $prazo]) {
            Ticket::factory()->create([
                'channel_id' => $canal->id,
                'status' => TicketStatus::AGUARDANDO,
                'waiting_since' => now()->addMinutes($espera),
                'sla_due_at' => now()->addMinutes($prazo),
            ]);
        }
    }
}
