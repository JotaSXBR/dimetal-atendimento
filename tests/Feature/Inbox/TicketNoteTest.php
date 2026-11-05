<?php

namespace Tests\Feature\Inbox;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketNoteTest extends TestCase
{
    use RefreshDatabase;

    private ?Channel $channel = null;

    private function ticket(TicketStatus $status, ?User $owner = null): Ticket
    {
        return Ticket::factory()->create(['channel_id' => ($this->channel ??= Channel::factory()->create())->id, 'status' => $status, 'assigned_user_id' => $owner?->id]);
    }

    public function test_so_o_autor_apaga_a_nota(): void
    {
        $autor = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $outro = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $note = TicketNote::factory()->for($this->ticket(TicketStatus::EM_ATENDIMENTO, $autor))->create(['user_id' => $autor->id]);

        $this->assertTrue($autor->can('delete', $note));
        $this->assertFalse($outro->can('delete', $note));
        $this->assertFalse($admin->can('delete', $note));
    }

    public function test_note_exige_ver_o_ticket_e_nao_encerrado(): void
    {
        $dono = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $estranho = User::factory()->create(['role' => UserRole::ATENDENTE]);

        $aberto = $this->ticket(TicketStatus::EM_ATENDIMENTO, $dono);
        $encerrado = $this->ticket(TicketStatus::ENCERRADO, $dono);

        $this->assertTrue($dono->can('note', $aberto));
        $this->assertFalse($estranho->can('note', $aberto));
        $this->assertFalse($dono->can('note', $encerrado));
    }

    public function test_nota_some_com_o_ticket(): void
    {
        $ticket = $this->ticket(TicketStatus::EM_ATENDIMENTO, User::factory()->create());
        $note = TicketNote::factory()->for($ticket)->create();

        $ticket->delete();

        $this->assertDatabaseMissing('ticket_notes', ['id' => $note->id]);
    }
}
