<?php

namespace Tests\Feature\Inbox;

use App\Actions\Inbox\ClaimTicket;
use App\Actions\Inbox\CloseTicket;
use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClaimAndCloseTest extends TestCase
{
    use RefreshDatabase;

    public function test_dois_assumir_em_sequencia_so_o_primeiro_vence(): void
    {
        $ticket = Ticket::factory()->create();
        [$a, $b] = User::factory()->count(2)->create();

        $this->assertTrue((new ClaimTicket)($ticket, $a));
        $this->assertFalse((new ClaimTicket)(Ticket::find($ticket->id), $b));
        $this->assertSame($a->id, $ticket->fresh()->assigned_user_id);
        $this->assertSame(1, $ticket->events()->where('type', 'claimed')->count());
    }

    public function test_assumir_muda_para_em_atendimento_e_grava_evento(): void
    {
        $ticket = Ticket::factory()->create();
        $user = User::factory()->create();

        (new ClaimTicket)($ticket, $user);

        $this->assertSame(TicketStatus::EM_ATENDIMENTO, $ticket->status);
        $this->assertSame($user->id, $ticket->events()->where('type', 'claimed')->value('actor_user_id'));
    }

    public function test_nao_assume_ticket_encerrado(): void
    {
        $ticket = Ticket::factory()->create(['status' => TicketStatus::ENCERRADO]);

        $this->assertFalse((new ClaimTicket)($ticket, User::factory()->create()));
    }

    public function test_encerrar_grava_closed_at_e_evento_com_ator(): void
    {
        $ticket = Ticket::factory()->create(['status' => TicketStatus::EM_ATENDIMENTO]);
        $user = User::factory()->create();

        (new CloseTicket)($ticket, $user);

        $this->assertSame(TicketStatus::ENCERRADO, $ticket->status);
        $this->assertNotNull($ticket->closed_at);
        $this->assertSame($user->id, $ticket->events()->where('type', 'closed')->value('actor_user_id'));
    }

    public function test_encerrar_duas_vezes_nao_duplica_evento(): void
    {
        $ticket = Ticket::factory()->create(['status' => TicketStatus::EM_ATENDIMENTO]);
        $user = User::factory()->create();

        (new CloseTicket)($ticket, $user);
        (new CloseTicket)($ticket, $user);

        $this->assertSame(1, $ticket->events()->where('type', 'closed')->count());
    }
}
