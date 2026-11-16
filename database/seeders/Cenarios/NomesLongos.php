<?php

namespace Database\Seeders\Cenarios;

use App\Enums\TicketStatus;
use App\Models\Contact;
use App\Models\Ticket;
use Illuminate\Database\Seeder;

class NomesLongos extends Seeder
{
    public function run(): void
    {
        $contato = Contact::factory()->create([
            'name' => 'Maria Aparecida Fernanda dos Santos Albuquerque de Oliveira Cavalcanti Pereira Neto',
            'company' => 'Distribuidora de Materiais de Construção e Ferragens Industriais Ltda do Vale do Paraíba',
        ]);

        Ticket::factory()->create(['contact_id' => $contato->id, 'channel_id' => CanalConectado::canal()->id, 'status' => TicketStatus::AGUARDANDO]);
    }
}
