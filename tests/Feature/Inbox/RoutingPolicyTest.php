<?php

namespace Tests\Feature\Inbox;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoutingPolicyTest extends TestCase
{
    use RefreshDatabase;

    private Channel $channel;

    private Sector $vendas;

    private Sector $financeiro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = Channel::factory()->create();
        $this->vendas = Sector::factory()->create(['name' => 'Vendas']);
        $this->financeiro = Sector::factory()->create(['name' => 'Financeiro']);
    }

    private function attendant(?Sector $sector = null, bool $canTransfer = true): User
    {
        $user = User::factory()->create(['role' => UserRole::ATENDENTE, 'can_transfer' => $canTransfer]);

        if ($sector !== null) {
            $user->sectors()->attach($sector);
        }

        return $user;
    }

    private function ticket(TicketStatus $status = TicketStatus::AGUARDANDO, ?Sector $sector = null, ?User $owner = null): Ticket
    {
        return Ticket::factory()->create([
            'channel_id' => $this->channel->id,
            'status' => $status,
            'sector_id' => $sector?->id,
            'assigned_user_id' => $owner?->id,
        ]);
    }

    public function test_atendente_ve_a_fila_sem_setor_e_a_do_proprio_setor(): void
    {
        $me = $this->attendant($this->vendas);
        $semSetor = $this->ticket();
        $meuSetor = $this->ticket(sector: $this->vendas);
        $outroSetor = $this->ticket(sector: $this->financeiro);

        $visible = Ticket::visibleTo($me)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$semSetor->id, $meuSetor->id], $visible);
        $this->assertNotContains($outroSetor->id, $visible);
        $this->assertTrue($me->can('view', $meuSetor));
        $this->assertFalse($me->can('view', $outroSetor));
    }

    public function test_atendente_ve_o_em_atendimento_do_proprio_setor_mas_nao_o_de_outro_nem_o_sem_setor(): void
    {
        $me = $this->attendant($this->vendas);
        $colega = $this->attendant($this->vendas);
        $noMeuSetor = $this->ticket(TicketStatus::EM_ATENDIMENTO, $this->vendas, $colega);
        $noOutroSetor = $this->ticket(TicketStatus::EM_ATENDIMENTO, $this->financeiro, $this->attendant($this->financeiro));
        $semSetor = $this->ticket(TicketStatus::EM_ATENDIMENTO, null, $colega);

        $this->assertTrue($me->can('view', $noMeuSetor));
        $this->assertFalse($me->can('view', $noOutroSetor));
        $this->assertFalse($me->can('view', $semSetor));
    }

    public function test_atendente_sem_setor_ve_so_o_que_nao_tem_setor_e_o_proprio(): void
    {
        $me = $this->attendant();
        $semSetor = $this->ticket();
        $comSetor = $this->ticket(sector: $this->vendas);
        $meu = $this->ticket(TicketStatus::EM_ATENDIMENTO, $this->financeiro, $me);

        $this->assertEqualsCanonicalizing([$semSetor->id, $meu->id], Ticket::visibleTo($me)->pluck('id')->all());
        $this->assertNotContains($comSetor->id, Ticket::visibleTo($me)->pluck('id')->all());
    }

    public function test_assumir_respeita_o_setor_do_atendente(): void
    {
        $me = $this->attendant($this->vendas);
        $supervisor = User::factory()->create(['role' => UserRole::SUPERVISOR]);

        $this->assertTrue($me->can('claim', $this->ticket(sector: $this->vendas)));
        $this->assertTrue($me->can('claim', $this->ticket()));
        $this->assertFalse($me->can('claim', $this->ticket(sector: $this->financeiro)));
        $this->assertTrue($supervisor->can('claim', $this->ticket(sector: $this->financeiro)));
    }

    public function test_transferir_exige_ser_o_responsavel_com_a_opcao_ligada_ou_supervisor(): void
    {
        $owner = $this->attendant($this->vendas);
        $blocked = $this->attendant($this->vendas, canTransfer: false);
        $supervisor = User::factory()->create(['role' => UserRole::SUPERVISOR]);
        $other = $this->attendant($this->vendas);

        $own = $this->ticket(TicketStatus::EM_ATENDIMENTO, $this->vendas, $owner);
        $blockedOwn = $this->ticket(TicketStatus::EM_ATENDIMENTO, $this->vendas, $blocked);
        $waiting = $this->ticket(sector: $this->vendas);

        $this->assertTrue($owner->can('transfer', $own));
        $this->assertFalse($blocked->can('transfer', $blockedOwn));
        $this->assertFalse($other->can('transfer', $own));
        $this->assertFalse($owner->can('transfer', $waiting));
        $this->assertTrue($supervisor->can('transfer', $own));
        $this->assertTrue($supervisor->can('transfer', $waiting));
        $this->assertFalse($supervisor->can('transfer', $this->ticket(TicketStatus::ENCERRADO)));
    }

    public function test_tomar_so_de_colega_do_mesmo_setor_e_com_a_opcao_ligada(): void
    {
        $me = $this->attendant($this->vendas);
        $blocked = $this->attendant($this->vendas, canTransfer: false);
        $otherSector = $this->attendant($this->financeiro);
        $colleague = $this->attendant($this->vendas);

        $ticket = $this->ticket(TicketStatus::EM_ATENDIMENTO, $this->vendas, $colleague);

        $this->assertTrue($me->can('takeover', $ticket));
        $this->assertFalse($blocked->can('takeover', $ticket));
        $this->assertFalse($otherSector->can('takeover', $ticket));
        $this->assertFalse($colleague->can('takeover', $ticket));
        $this->assertFalse($me->can('takeover', $this->ticket(sector: $this->vendas)));
        $this->assertFalse($me->can('takeover', $this->ticket(TicketStatus::EM_ATENDIMENTO, null, $colleague)));
    }

    public function test_supervisor_toma_de_qualquer_um_e_atendente_nao_reatribui(): void
    {
        $supervisor = User::factory()->create(['role' => UserRole::SUPERVISOR]);
        $attendant = $this->attendant($this->vendas);
        $ticket = $this->ticket(TicketStatus::EM_ATENDIMENTO, $this->financeiro, $this->attendant($this->financeiro));

        $this->assertTrue($supervisor->can('takeover', $ticket));
        $this->assertTrue($supervisor->can('reassign', $ticket));
        $this->assertFalse($attendant->can('reassign', $ticket));
        $this->assertFalse($supervisor->can('reassign', $this->ticket(TicketStatus::ENCERRADO)));
    }
}
