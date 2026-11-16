<?php

namespace Database\Seeders\Cenarios;

use App\Enums\TagScope;
use App\Enums\TicketStatus;
use App\Models\Contact;
use App\Models\Tag;
use App\Models\Ticket;
use Illuminate\Database\Seeder;

class EtiquetasDosDoisEscopos extends Seeder
{
    public function run(): void
    {
        $contato = Contact::factory()->create();
        $ticket = Ticket::factory()->create(['contact_id' => $contato->id, 'channel_id' => CanalConectado::canal()->id, 'status' => TicketStatus::AGUARDANDO]);

        // As etiquetas padrão (TagSeeder) cobrem os dois escopos: sobram as não aplicadas.
        $contato->tags()->syncWithoutDetaching([Tag::query()->where('scope', TagScope::CONTATO)->firstOrFail()->id]);
        $ticket->tags()->syncWithoutDetaching([Tag::query()->where('scope', TagScope::CONVERSA)->firstOrFail()->id]);
    }
}
