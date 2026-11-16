<?php

namespace Database\Seeders\Cenarios;

use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Enums\TicketStatus;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketNote;
use Database\Seeders\SoRodaEmAmbienteDeTeste;
use Illuminate\Database\Seeder;

class ConversaDaAutomacao extends Seeder
{
    use SoRodaEmAmbienteDeTeste;

    public function run(): void
    {
        $this->exigirAmbienteDeTeste();

        $ticket = Ticket::factory()->create(['channel_id' => CanalConectado::canal()->id, 'status' => TicketStatus::AGUARDANDO]);

        Message::factory()->create(['ticket_id' => $ticket->id, 'body' => 'Oi, quero falar com vendas']);
        Message::factory()->create(['ticket_id' => $ticket->id, 'direction' => MessageDirection::OUT, 'origin' => MessageOrigin::BOT, 'body' => "Escolha uma opção:\n1 - Vendas\n2 - Suporte\n3 - Financeiro"]);
        TicketNote::factory()->bot()->create(['ticket_id' => $ticket->id, 'body' => 'Nome: Ana · Setor escolhido: Vendas']);
    }
}
