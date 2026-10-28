<?php

namespace Tests\Feature\Domain;

use App\Enums\TicketStatus;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InboxSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_so_um_ticket_aberto_por_contato_e_canal(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create();

        Ticket::factory()->for($contact)->for($channel)->create();

        $this->expectException(QueryException::class);

        Ticket::factory()->for($contact)->for($channel)->create();
    }

    public function test_ticket_encerrado_nao_conta_como_aberto(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->create();

        Ticket::factory()->for($contact)->for($channel)->create(['status' => TicketStatus::ENCERRADO]);
        Ticket::factory()->for($contact)->for($channel)->create();

        $this->assertSame(1, Ticket::query()->open()->count());
    }

    public function test_wa_message_id_e_unico_por_canal(): void
    {
        $a = Channel::factory()->create(['waha_session' => 'a']);
        $b = Channel::factory()->create(['waha_session' => 'b']);

        $ticketA = Ticket::factory()->for($a)->create();
        $ticketB = Ticket::factory()->for($b)->create();

        Message::factory()->for($ticketA)->create(['wa_message_id' => 'false_x_1']);
        Message::factory()->for($ticketB)->create(['wa_message_id' => 'false_x_1']);

        $this->expectException(QueryException::class);

        Message::factory()->for($ticketA)->create(['wa_message_id' => 'false_x_1']);
    }

    public function test_varias_mensagens_pendentes_sem_wa_message_id_sao_aceitas(): void
    {
        $ticket = Ticket::factory()->create();

        Message::factory()->for($ticket)->count(2)->create(['wa_message_id' => null]);

        $this->assertSame(2, $ticket->messages()->count());
    }
}
