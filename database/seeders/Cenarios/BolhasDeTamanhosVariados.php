<?php

namespace Database\Seeders\Cenarios;

use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Enums\TicketStatus;
use App\Models\Message;
use App\Models\Ticket;
use App\Services\Inbox\SendTicketMessage;
use Database\Seeders\SoRodaEmAmbienteDeTeste;
use Illuminate\Database\Seeder;

class BolhasDeTamanhosVariados extends Seeder
{
    use SoRodaEmAmbienteDeTeste;

    public function run(): void
    {
        $this->exigirAmbienteDeTeste();

        $ticket = Ticket::factory()->create(['channel_id' => CanalConectado::canal()->id, 'status' => TicketStatus::EM_ATENDIMENTO, 'assigned_user_id' => UsuariosDeTeste::admin()->id]); // só o responsável abre o menu da bolha
        $saida = ['ticket_id' => $ticket->id, 'direction' => MessageDirection::OUT, 'origin' => MessageOrigin::PAINEL];

        Message::factory()->create(['ticket_id' => $ticket->id, 'body' => 'Oi']);
        Message::factory()->create([...$saida, 'body' => 'Ok']);
        $longa = Message::factory()->create(['ticket_id' => $ticket->id, 'body' => str_repeat('Texto longo para testar a quebra de linha da bolha. ', 11)]);
        Message::factory()->create([...$saida, 'body' => 'Resposta citando a mensagem longa.', ...SendTicketMessage::quoteColumns($longa)]);
    }
}
