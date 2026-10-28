<?php

namespace Tests\Feature\Inbox;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TicketPolicyTest extends TestCase
{
    use RefreshDatabase;

    private ?Channel $channel = null;

    private function ticket(TicketStatus $status, ?User $owner = null): Ticket
    {
        return Ticket::factory()->create(['channel_id' => ($this->channel ??= Channel::factory()->create())->id, 'status' => $status, 'assigned_user_id' => $owner?->id]);
    }

    public function test_atendente_ve_aguardando_e_os_proprios_mas_nao_os_de_outro(): void
    {
        $a = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $b = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $fila = $this->ticket(TicketStatus::AGUARDANDO);
        $meu = $this->ticket(TicketStatus::EM_ATENDIMENTO, $a);
        $dele = $this->ticket(TicketStatus::EM_ATENDIMENTO, $b);

        $this->assertTrue($a->can('view', $fila));
        $this->assertTrue($a->can('view', $meu));
        $this->assertFalse($a->can('view', $dele));
        $this->assertEqualsCanonicalizing([$fila->id, $meu->id], Ticket::visibleTo($a)->pluck('id')->all());
    }

    public function test_supervisor_ve_tudo_e_inativo_nada(): void
    {
        $sup = User::factory()->create(['role' => UserRole::SUPERVISOR]);
        $inativo = User::factory()->create(['role' => UserRole::ADMIN, 'active' => false]);
        $t = $this->ticket(TicketStatus::EM_ATENDIMENTO, User::factory()->create());

        $this->assertTrue($sup->can('view', $t));
        $this->assertFalse($inativo->can('view', $t));
        $this->assertSame(0, Ticket::visibleTo($inativo)->count());
    }

    public function test_reply_exige_ticket_assumido(): void
    {
        $dono = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $sup = User::factory()->create(['role' => UserRole::SUPERVISOR]);
        $fila = $this->ticket(TicketStatus::AGUARDANDO);
        $assumido = $this->ticket(TicketStatus::EM_ATENDIMENTO, $dono);

        $this->assertFalse($sup->can('reply', $fila));
        $this->assertTrue($sup->can('reply', $assumido));
        $this->assertTrue($dono->can('reply', $assumido));
        $this->assertFalse(User::factory()->create(['role' => UserRole::ATENDENTE])->can('reply', $assumido));
    }

    public function test_claim_so_sem_responsavel_e_nao_encerrado(): void
    {
        $u = User::factory()->create(['role' => UserRole::ATENDENTE]);

        $this->assertTrue($u->can('claim', $this->ticket(TicketStatus::AGUARDANDO)));
        $this->assertFalse($u->can('claim', $this->ticket(TicketStatus::EM_ATENDIMENTO, User::factory()->create())));
        $this->assertFalse($u->can('claim', $this->ticket(TicketStatus::ENCERRADO)));
    }

    public function test_close_pelo_responsavel_ou_supervisor(): void
    {
        $dono = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $sup = User::factory()->create(['role' => UserRole::SUPERVISOR]);
        $t = $this->ticket(TicketStatus::EM_ATENDIMENTO, $dono);

        $this->assertTrue($dono->can('close', $t));
        $this->assertTrue($sup->can('close', $t));
        $this->assertFalse(User::factory()->create(['role' => UserRole::ATENDENTE])->can('close', $t));
    }
}
