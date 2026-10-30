<?php

namespace Tests\Feature\Sla;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class InboxSlaTest extends TestCase
{
    use RefreshDatabase;

    private Channel $channel;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = Channel::factory()->create();
        $this->agent = User::factory()->create(['role' => UserRole::ATENDENTE, 'sign_messages' => false]);
    }

    /** Um horário de São Paulo (sexta 30/10/2026) como o instante em UTC. */
    private function sp(string $dateTime): CarbonImmutable
    {
        return CarbonImmutable::parse($dateTime, 'America/Sao_Paulo')->utc();
    }

    private function waiting(string $name, array $override = []): Ticket
    {
        return Ticket::factory()->create(array_replace([
            'channel_id' => $this->channel->id,
            'contact_id' => Contact::factory()->create(['name' => $name])->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            'assigned_user_id' => $this->agent->id,
            'waiting_since' => $this->sp('2026-10-30 10:00'),
            'sla_warn_at' => $this->sp('2026-10-30 10:07:30'),
            'sla_due_at' => $this->sp('2026-10-30 10:10'),
        ], $override));
    }

    private function inbox(): Testable
    {
        return Livewire::actingAs($this->agent)->test(Inbox::class);
    }

    public function test_estado_do_sla_pelo_relogio(): void
    {
        $ticket = $this->waiting('Maria');

        $this->assertNull($ticket->slaState($this->sp('2026-10-30 10:07:29')));
        $this->assertSame('perto', $ticket->slaState($this->sp('2026-10-30 10:07:30')));
        $this->assertSame('perto', $ticket->slaState($this->sp('2026-10-30 10:09:59')));
        $this->assertSame('estourou', $ticket->slaState($this->sp('2026-10-30 10:10:00')));
    }

    public function test_sem_espera_ou_encerrado_nao_tem_estado(): void
    {
        $this->travelTo($this->sp('2026-10-30 10:30'));

        $this->assertNull($this->waiting('A', ['waiting_since' => null])->slaState());
        $this->assertNull($this->waiting('B', ['status' => TicketStatus::ENCERRADO, 'closed_at' => $this->sp('2026-10-30 10:20')])->slaState());
    }

    public function test_selo_perto_e_estourou_na_lista(): void
    {
        $this->travelTo($this->sp('2026-10-30 10:08'));
        $this->waiting('Quase Vencendo');
        $this->waiting('Ja Vencido', ['sla_warn_at' => $this->sp('2026-10-30 10:00:30'), 'sla_due_at' => $this->sp('2026-10-30 10:05')]);
        $this->waiting('Tranquilo', ['sla_warn_at' => $this->sp('2026-10-30 10:30'), 'sla_due_at' => $this->sp('2026-10-30 10:40')]);

        $html = $this->inbox()->html();

        $this->assertSame(1, substr_count($html, 'Perto de estourar'));
        $this->assertSame(1, substr_count($html, 'SLA estourado'));
    }

    public function test_cliente_esperando_dentro_do_prazo_mostra_o_horario_limite_na_lista(): void
    {
        $this->travelTo($this->sp('2026-10-30 10:00'));
        $this->waiting('Tranquilo', ['sla_warn_at' => $this->sp('2026-10-30 10:30'), 'sla_due_at' => $this->sp('2026-10-30 10:40')]);

        $this->inbox()->assertSee('Responder até hoje às 10h40')->assertDontSee('Perto de estourar');
    }

    public function test_sem_espera_nao_ha_selo(): void
    {
        $this->travelTo($this->sp('2026-10-30 11:00'));
        $this->waiting('Respondido', ['waiting_since' => null, 'sla_warn_at' => null, 'sla_due_at' => null]);

        $this->inbox()->assertDontSee('Perto de estourar')->assertDontSee('SLA estourado');
    }

    public function test_horario_limite_na_conversa_com_o_dia(): void
    {
        $this->travelTo($this->sp('2026-10-30 10:00'));
        $today = $this->waiting('Hoje');
        $tomorrow = $this->waiting('Amanha', ['sla_warn_at' => $this->sp('2026-10-31 08:07:30'), 'sla_due_at' => $this->sp('2026-10-31 08:10')]);

        $this->inbox()->call('select', $today->id)->assertSee('Responder até hoje às 10h10');
        $this->inbox()->call('select', $tomorrow->id)->assertSee('Responder até amanhã às 8h10');
    }

    public function test_estourado_mostra_quando_venceu(): void
    {
        $this->travelTo($this->sp('2026-10-30 10:30'));
        $ticket = $this->waiting('Atrasado');

        $this->inbox()->call('select', $ticket->id)->assertSee('SLA estourado')->assertSee('venceu hoje às 10h10');
    }

    public function test_atendente_nao_ve_selo_de_ticket_que_nao_pode_ver(): void
    {
        $this->travelTo($this->sp('2026-10-30 10:30'));
        $other = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $this->waiting('Do Colega', ['assigned_user_id' => $other->id, 'sector_id' => Sector::factory()->create()->id]);

        $this->inbox()->assertDontSee('SLA estourado')->assertDontSee('Do Colega');
    }

    public function test_o_estado_muda_com_o_tempo_sem_a_rotina(): void
    {
        $this->waiting('Relogio');

        $this->travelTo($this->sp('2026-10-30 10:05'));
        $this->inbox()->assertDontSee('Perto de estourar')->assertDontSee('SLA estourado');

        $this->travelTo($this->sp('2026-10-30 10:08'));
        $this->inbox()->assertSee('Perto de estourar');

        $this->travelTo($this->sp('2026-10-30 10:11'));
        $this->inbox()->assertSee('SLA estourado')->assertDontSee('Perto de estourar');
    }
}
