<?php

namespace Tests\Feature\Bot;

use App\Actions\Inbox\ClaimTicket;
use App\Actions\Inbox\TransferTicket;
use App\Enums\ClosedReason;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Services\Inbox\SlaClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PendingStatusTest extends TestCase
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

    private function attendant(?Sector $sector = null, bool $canTransfer = false): User
    {
        $user = User::factory()->create(['role' => UserRole::ATENDENTE, 'can_transfer' => $canTransfer]);

        if ($sector !== null) {
            $user->sectors()->attach($sector);
        }

        return $user;
    }

    private function pending(array $override = []): Ticket
    {
        return Ticket::factory()->create([
            'channel_id' => $this->channel->id,
            'status' => TicketStatus::PENDENTE,
            ...$override,
        ]);
    }

    public function test_pendente_nao_e_fila_humana(): void
    {
        $this->assertSame('Pendente', TicketStatus::PENDENTE->label());
        $this->assertFalse(TicketStatus::PENDENTE->inHumanQueue());
        $this->assertTrue(TicketStatus::AGUARDANDO->inHumanQueue());
        $this->assertFalse(TicketStatus::EM_ATENDIMENTO->inHumanQueue());
        $this->assertSame('bot', ClosedReason::BOT->value);
    }

    public function test_pendente_nao_pode_ser_assumido_nem_respondido(): void
    {
        $me = $this->attendant($this->vendas);
        $ticket = $this->pending();

        $this->assertFalse($me->can('claim', $ticket));
        $this->assertFalse($me->can('reply', $ticket));
        $this->assertFalse((new ClaimTicket)($ticket, $me));
        $this->assertSame(TicketStatus::PENDENTE, $ticket->fresh()?->status);
        $this->assertNull($ticket->fresh()?->assigned_user_id);
    }

    public function test_qualquer_usuario_ativo_ve_o_pendente_em_leitura(): void
    {
        $outroSetor = $this->attendant($this->financeiro);
        $semSetor = $this->attendant();
        $ticket = $this->pending(['sector_id' => $this->vendas->id]);

        foreach ([$outroSetor, $semSetor] as $user) {
            $this->assertTrue(Ticket::query()->visibleTo($user)->whereKey($ticket->id)->exists());
            $this->assertTrue($user->can('view', $ticket));
        }

        $inactive = User::factory()->create(['role' => UserRole::ATENDENTE, 'active' => false]);
        $this->assertFalse(Ticket::query()->visibleTo($inactive)->whereKey($ticket->id)->exists());
    }

    public function test_atendente_transfere_pendente_e_ele_vira_aguardando_com_relogio_novo(): void
    {
        $me = $this->attendant($this->financeiro, canTransfer: false);
        $ticket = $this->pending([
            'waiting_since' => now()->subHour(),
            'sla_warn_at' => now()->subMinutes(10),
            'sla_due_at' => now()->addMinutes(10),
            'sla_warned_at' => now()->subMinutes(5),
        ]);

        $this->assertTrue($me->can('transfer', $ticket));

        $this->assertTrue((new TransferTicket)($ticket, $me, $this->vendas));

        $fresh = $ticket->fresh();
        $this->assertSame(TicketStatus::AGUARDANDO, $fresh?->status);
        $this->assertSame($this->vendas->id, $fresh?->sector_id);
        $this->assertEqualsWithDelta(now()->timestamp, $fresh?->waiting_since?->timestamp, 5);
        $this->assertNull($fresh?->sla_warn_at);
        $this->assertNull($fresh?->sla_due_at);
        $this->assertNull($fresh?->sla_warned_at);
    }

    public function test_transfer_aceita_ator_nulo(): void
    {
        $ticket = $this->pending();

        $this->assertTrue((new TransferTicket)($ticket, null, $this->vendas));

        $event = TicketEvent::query()->where('ticket_id', $ticket->id)->where('type', 'transferred')->firstOrFail();
        $this->assertNull($event->actor_user_id);
    }

    public function test_transferir_ticket_comum_nao_mexe_no_relogio(): void
    {
        $waiting = now()->subMinutes(30)->startOfSecond();
        $ticket = Ticket::factory()->create([
            'channel_id' => $this->channel->id,
            'status' => TicketStatus::AGUARDANDO,
            'waiting_since' => $waiting,
        ]);

        (new TransferTicket)($ticket, $this->attendant(), $this->vendas);

        $this->assertSame($waiting->timestamp, $ticket->fresh()?->waiting_since?->timestamp);
    }

    public function test_pendente_nao_entra_no_sla(): void
    {
        $ticket = $this->pending(['waiting_since' => now()->subHours(5)]);

        app(SlaClock::class)();

        $fresh = $ticket->fresh();
        $this->assertNull($fresh?->sla_due_at);
        $this->assertNull($fresh?->sla_warn_at);
    }
}
