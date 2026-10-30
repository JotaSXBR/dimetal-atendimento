<?php

namespace Tests\Feature\Reports;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Reports\Index;
use App\Models\Channel;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Reports\AttendanceReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AttendanceReportTest extends TestCase
{
    use RefreshDatabase;

    private Channel $channel;

    private Sector $a;

    private Sector $b;

    private User $ana;

    private User $bia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-30 12:00:00'));
        $this->channel = Channel::factory()->connected()->create();
        $this->a = Sector::factory()->create(['name' => 'Comercial']);
        $this->b = Sector::factory()->create(['name' => 'Financeiro']);
        $this->ana = User::factory()->create(['name' => 'Ana']);
        $this->bia = User::factory()->create(['name' => 'Bia']);
    }

    /** @param array<string, mixed> $attributes */
    private function ticket(Sector $sector, string $opened, ?string $reply, TicketStatus $status, ?User $agent = null, array $attributes = []): Ticket
    {
        $at = fn (?string $time) => $time === null ? null : CarbonImmutable::parse('2026-10-30 '.$time);

        return Ticket::factory()->for($this->channel)->create([
            'sector_id' => $sector->id,
            'status' => $status,
            'assigned_user_id' => $agent?->id,
            'opened_at' => $at($opened),
            'first_human_reply_at' => $at($reply),
            ...$attributes,
        ]);
    }

    private function seedTickets(): void
    {
        $this->ticket($this->a, '10:00', '10:05', TicketStatus::ENCERRADO, $this->ana);
        $this->ticket($this->a, '10:10', '10:30', TicketStatus::EM_ATENDIMENTO, $this->ana);
        $this->ticket($this->b, '11:00', '11:02', TicketStatus::ENCERRADO, $this->bia);
        // 11h30 já estourou o SLA de 10 min (venceu 11h40); 11h55 ainda está dentro (vence 12h05).
        $this->ticket($this->b, '11:30', null, TicketStatus::AGUARDANDO, null, ['waiting_since' => CarbonImmutable::parse('2026-10-30 11:30'), 'sla_warn_at' => CarbonImmutable::parse('2026-10-30 11:37:30'), 'sla_due_at' => CarbonImmutable::parse('2026-10-30 11:40')]);
        $this->ticket($this->b, '11:55', null, TicketStatus::AGUARDANDO, null, ['waiting_since' => CarbonImmutable::parse('2026-10-30 11:55'), 'sla_warn_at' => CarbonImmutable::parse('2026-10-30 12:02:30'), 'sla_due_at' => CarbonImmutable::parse('2026-10-30 12:05')]);
        $this->ticket($this->a, '10:00', '10:01', TicketStatus::ENCERRADO, $this->ana, ['opened_at' => now()->subDays(20), 'first_human_reply_at' => now()->subDays(20)->addMinute()]);
    }

    private function report(): array
    {
        return app(AttendanceReport::class)->for(now()->subDays(7)->startOfDay(), now(), 10);
    }

    public function test_totais_mediana_sla_e_vencidos(): void
    {
        $this->seedTickets();

        $report = $this->report();

        $this->assertSame(5, $report['opened']);
        $this->assertSame(300, $report['median_seconds']);
        $this->assertSame(66.7, $report['sla_percent']);
        $this->assertSame(1, $report['overdue'], 'só o ticket que estourou o vencimento do SLA conta');
    }

    public function test_volume_por_setor_e_desempenho_por_atendente(): void
    {
        $this->seedTickets();

        $report = $this->report();

        $this->assertSame([['name' => 'Financeiro', 'count' => 3], ['name' => 'Comercial', 'count' => 2]], $report['by_sector']);
        $this->assertSame([
            ['name' => 'Ana', 'assigned' => 2, 'closed' => 1, 'median_seconds' => 750],
            ['name' => 'Bia', 'assigned' => 1, 'closed' => 1, 'median_seconds' => 120],
        ], $report['by_agent']);
    }

    public function test_periodo_sem_tickets_nao_divide_por_zero(): void
    {
        $report = $this->report();

        $this->assertSame(0, $report['opened']);
        $this->assertNull($report['median_seconds']);
        $this->assertNull($report['sla_percent']);
        $this->assertSame(0, $report['overdue']);
        $this->assertSame([], $report['by_sector']);
        $this->assertSame([], $report['by_agent']);
    }

    public function test_hoje_comeca_a_meia_noite_de_sao_paulo_e_nao_de_utc(): void
    {
        // 22h em Brasília de 30/10 já é 01h UTC de 31/10.
        $this->travelTo(CarbonImmutable::parse('2026-10-31 01:00:00'));
        $this->ticket($this->a, '12:00', null, TicketStatus::AGUARDANDO, null, ['opened_at' => CarbonImmutable::parse('2026-10-30 12:00:00')]);
        $this->ticket($this->a, '12:00', null, TicketStatus::AGUARDANDO, null, ['opened_at' => CarbonImmutable::parse('2026-10-30 02:00:00')]);

        $opened = Livewire::actingAs(User::factory()->create(['role' => UserRole::SUPERVISOR]))->test(Index::class)->set('period', 'hoje')->viewData('data')['opened'];

        $this->assertSame(1, $opened, 'só o ticket aberto às 09h de Brasília é de hoje');
    }

    public function test_so_supervisor_e_admin_abrem_a_pagina(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::ATENDENTE]))->get('/relatorios')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::SUPERVISOR]))->get('/relatorios')->assertOk();
    }

    public function test_a_pagina_mostra_os_numeros_e_troca_de_periodo(): void
    {
        $this->seedTickets();
        $this->ticket($this->a, '09:00', '09:03', TicketStatus::ENCERRADO, $this->ana, ['opened_at' => now()->subDays(10), 'first_human_reply_at' => now()->subDays(10)->addMinutes(3)]);

        $page = Livewire::actingAs(User::factory()->create(['role' => UserRole::SUPERVISOR]))->test(Index::class);

        $page->assertSet('period', '7')->assertSee('Comercial')->assertSee('Ana')->assertSee('5 min 0 s')->assertSee('66,7%');
        $page->set('period', '30')->assertSee('6');
        $page->set('period', 'hoje')->assertSee('Tickets abertos');
    }
}
