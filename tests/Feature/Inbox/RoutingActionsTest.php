<?php

namespace Tests\Feature\Inbox;

use App\Actions\Inbox\CloseTicket;
use App\Actions\Inbox\ReassignTicket;
use App\Actions\Inbox\TakeoverTicket;
use App\Actions\Inbox\TransferTicket;
use App\Enums\ClosedReason;
use App\Enums\SectorStatus;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoutingActionsTest extends TestCase
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

    private function attendant(Sector $sector): User
    {
        $user = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $user->sectors()->attach($sector);

        return $user;
    }

    private function ticket(TicketStatus $status, ?Sector $sector, ?User $owner = null): Ticket
    {
        return Ticket::factory()->create([
            'channel_id' => $this->channel->id,
            'status' => $status,
            'sector_id' => $sector?->id,
            'assigned_user_id' => $owner?->id,
        ]);
    }

    public function test_transferir_devolve_o_ticket_a_fila_do_setor_sem_responsavel_e_registra_o_evento(): void
    {
        $owner = $this->attendant($this->vendas);
        $ticket = $this->ticket(TicketStatus::EM_ATENDIMENTO, $this->vendas, $owner);

        $this->assertTrue((new TransferTicket)($ticket, $owner, $this->financeiro, '  cliente quer boleto  '));

        $this->assertSame($this->financeiro->id, $ticket->sector_id);
        $this->assertNull($ticket->assigned_user_id);
        $this->assertSame(TicketStatus::AGUARDANDO, $ticket->status);

        $event = $ticket->events()->where('type', 'transferred')->sole();
        $this->assertSame($owner->id, $event->actor_user_id);
        $this->assertSame([
            'from_sector' => $this->vendas->id,
            'to_sector' => $this->financeiro->id,
            'from_user' => $owner->id,
            'note' => 'cliente quer boleto',
        ], $event->payload);
    }

    public function test_transferir_para_setor_desativado_e_recusado(): void
    {
        $closed = Sector::factory()->create(['name' => 'Antigo', 'status' => SectorStatus::DESATIVADO]);
        $ticket = $this->ticket(TicketStatus::AGUARDANDO, $this->vendas);

        $this->assertFalse((new TransferTicket)($ticket, User::factory()->create(), $closed));
        $this->assertSame($this->vendas->id, $ticket->fresh()?->sector_id);
        $this->assertSame(0, $ticket->events()->count());
    }

    public function test_transferir_para_setor_fora_do_menu_e_aceito(): void
    {
        $hidden = Sector::factory()->create(['name' => 'Interno', 'status' => SectorStatus::FORA_DO_MENU]);
        $ticket = $this->ticket(TicketStatus::AGUARDANDO, $this->vendas);

        $this->assertTrue((new TransferTicket)($ticket, User::factory()->create(), $hidden));
        $this->assertSame($hidden->id, $ticket->sector_id);
    }

    public function test_transferir_nao_muda_nada_quando_ja_esta_no_destino_sem_dono_ou_encerrado(): void
    {
        $actor = User::factory()->create();
        $parked = $this->ticket(TicketStatus::AGUARDANDO, $this->vendas);
        $closed = $this->ticket(TicketStatus::ENCERRADO, $this->vendas);

        $this->assertFalse((new TransferTicket)($parked, $actor, $this->vendas));
        $this->assertFalse((new TransferTicket)($closed, $actor, $this->financeiro));
        $this->assertSame(0, $parked->events()->count() + $closed->events()->count());
    }

    public function test_transferir_ticket_sem_setor_grava_so_o_setor_de_destino(): void
    {
        $ticket = $this->ticket(TicketStatus::AGUARDANDO, null);

        $this->assertTrue((new TransferTicket)($ticket, User::factory()->create(), $this->vendas));

        $this->assertSame(['to_sector' => $this->vendas->id], $ticket->events()->where('type', 'transferred')->sole()->payload);
    }

    public function test_reatribuir_escolhe_o_responsavel_e_registra_de_quem_para_quem(): void
    {
        $supervisor = User::factory()->create(['role' => UserRole::SUPERVISOR]);
        $before = $this->attendant($this->vendas);
        $after = $this->attendant($this->vendas);
        $ticket = $this->ticket(TicketStatus::EM_ATENDIMENTO, $this->vendas, $before);

        $this->assertTrue((new ReassignTicket)($ticket, $supervisor, $after));

        $this->assertSame($after->id, $ticket->assigned_user_id);
        $this->assertSame(TicketStatus::EM_ATENDIMENTO, $ticket->status);
        $this->assertSame(['from_user' => $before->id, 'to_user' => $after->id], $ticket->events()->where('type', 'reassigned')->sole()->payload);
    }

    public function test_reatribuir_recusa_inativo_outro_setor_e_o_mesmo_responsavel(): void
    {
        $supervisor = User::factory()->create(['role' => UserRole::SUPERVISOR]);
        $owner = $this->attendant($this->vendas);
        $ticket = $this->ticket(TicketStatus::EM_ATENDIMENTO, $this->vendas, $owner);

        $inactive = $this->attendant($this->vendas);
        $inactive->forceFill(['active' => false])->save();

        $this->assertFalse((new ReassignTicket)($ticket, $supervisor, $inactive));
        $this->assertFalse((new ReassignTicket)($ticket, $supervisor, $this->attendant($this->financeiro)));
        $this->assertFalse((new ReassignTicket)($ticket, $supervisor, $owner));
        $this->assertSame($owner->id, $ticket->fresh()?->assigned_user_id);
    }

    public function test_tomar_passa_o_ticket_para_quem_tomou(): void
    {
        $colleague = $this->attendant($this->vendas);
        $me = $this->attendant($this->vendas);
        $ticket = $this->ticket(TicketStatus::EM_ATENDIMENTO, $this->vendas, $colleague);

        $this->assertTrue((new TakeoverTicket)($ticket, $me, $colleague->id));

        $this->assertSame($me->id, $ticket->assigned_user_id);
        $this->assertSame(['from_user' => $colleague->id, 'to_user' => $me->id], $ticket->events()->where('type', 'taken_over')->sole()->payload);
    }

    public function test_dois_tomando_o_mesmo_ticket_so_o_primeiro_vence(): void
    {
        $colleague = $this->attendant($this->vendas);
        [$a, $b] = [$this->attendant($this->vendas), $this->attendant($this->vendas)];
        $ticket = $this->ticket(TicketStatus::EM_ATENDIMENTO, $this->vendas, $colleague);

        // Os dois viram o ticket com o colega como responsável.
        $this->assertTrue((new TakeoverTicket)($ticket, $a, $colleague->id));
        $this->assertFalse((new TakeoverTicket)(Ticket::findOrFail($ticket->id), $b, $colleague->id));

        $this->assertSame($a->id, $ticket->fresh()?->assigned_user_id);
        $this->assertSame(1, $ticket->events()->where('type', 'taken_over')->count());
    }

    public function test_encerrar_grava_o_motivo(): void
    {
        $user = User::factory()->create();
        $manual = $this->ticket(TicketStatus::EM_ATENDIMENTO, $this->vendas, $user);
        $auto = $this->ticket(TicketStatus::AGUARDANDO, $this->vendas);

        (new CloseTicket)($manual, $user);
        (new CloseTicket)($auto, $user, ClosedReason::INATIVIDADE);

        $this->assertSame(ClosedReason::MANUAL, $manual->closed_reason);
        $this->assertSame(['reason' => 'manual'], $manual->events()->where('type', 'closed')->sole()->payload);
        $this->assertSame(ClosedReason::INATIVIDADE, $auto->closed_reason);
    }
}
