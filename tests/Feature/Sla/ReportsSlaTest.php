<?php

namespace Tests\Feature\Sla;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Reports\Index;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Reports\AttendanceReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class ReportsSlaTest extends TestCase
{
    use RefreshDatabase;

    private Channel $channel;

    private Sector $sector;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo($this->sp('2026-10-30 10:30'));
        $this->channel = Channel::factory()->create();
        $this->sector = Sector::factory()->create(['name' => 'Comercial']);
        $this->agent = User::factory()->create(['role' => UserRole::ATENDENTE, 'name' => 'Ana Atendente']);
    }

    /** Um horário de São Paulo como o instante em UTC (o Eloquent grava no fuso do objeto). */
    private function sp(string $dateTime): CarbonImmutable
    {
        return CarbonImmutable::parse($dateTime, 'America/Sao_Paulo')->utc();
    }

    private function waiting(string $name, string $warn, string $due, array $override = []): Ticket
    {
        return Ticket::factory()->create(array_replace([
            'channel_id' => $this->channel->id,
            'contact_id' => Contact::factory()->create(['name' => $name])->id,
            'sector_id' => $this->sector->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            'assigned_user_id' => $this->agent->id,
            'waiting_since' => $this->sp('2026-10-30 10:00'),
            'sla_warn_at' => $this->sp($warn),
            'sla_due_at' => $this->sp($due),
        ], $override));
    }

    private function supervisorPage(): Testable
    {
        return Livewire::actingAs(User::factory()->create(['role' => UserRole::SUPERVISOR]))->test(Index::class);
    }

    /** @return array<string, mixed> */
    private function report(): array
    {
        return app(AttendanceReport::class)->for(now()->subDays(7)->startOfDay(), now(), 10);
    }

    public function test_lista_so_perto_e_estourou_do_mais_atrasado_ao_menos(): void
    {
        $this->waiting('Vencido Recente', '10:20', '10:25');
        $this->waiting('Vencido Antigo', '10:05', '10:10');
        $this->waiting('Perto', '10:25', '10:35');
        $this->waiting('Tranquilo', '10:40', '10:50');

        $waiting = $this->report()['waiting'];

        $this->assertSame(['Vencido Antigo', 'Vencido Recente', 'Perto'], array_column($waiting, 'contact'));
        $this->assertSame(['estourou', 'estourou', 'perto'], array_column($waiting, 'state'));
        $this->assertSame('Comercial', $waiting[0]['sector']);
        $this->assertSame('Ana Atendente', $waiting[0]['agent']);
        $this->assertSame('2026-10-30 10:10:00', $waiting[0]['due']->setTimezone('America/Sao_Paulo')->format('Y-m-d H:i:s'));
    }

    public function test_encerrado_e_ticket_sem_espera_nao_aparecem(): void
    {
        $this->waiting('Encerrado', '10:05', '10:10', ['status' => TicketStatus::ENCERRADO, 'closed_at' => $this->sp('2026-10-30 10:12')]);
        $this->waiting('Sem Espera', '10:05', '10:10', ['waiting_since' => null]);

        $this->assertSame([], $this->report()['waiting']);
        $this->assertSame(0, $this->report()['overdue']);
    }

    public function test_overdue_conta_os_estourados_e_nao_os_que_estao_so_perto(): void
    {
        $this->waiting('Vencido', '10:05', '10:10');
        $this->waiting('Perto', '10:25', '10:35');

        $this->assertSame(1, $this->report()['overdue']);
    }

    public function test_a_lista_tem_no_maximo_50_linhas(): void
    {
        $contact = Contact::factory()->create();
        Ticket::factory()->count(55)->create([
            'channel_id' => $this->channel->id,
            'status' => TicketStatus::AGUARDANDO,
            'waiting_since' => $this->sp('2026-10-30 10:00'),
            'sla_warn_at' => $this->sp('2026-10-30 10:05'),
            'sla_due_at' => $this->sp('2026-10-30 10:10'),
        ]);

        $this->assertCount(50, $this->report()['waiting']);
        $this->assertNotNull($contact->id);
    }

    public function test_a_tela_mostra_a_lista_com_o_estado(): void
    {
        $this->waiting('Cliente Atrasado', '10:05', '10:10');
        $this->waiting('Cliente Quase', '10:25', '10:35');

        $this->supervisorPage()
            ->assertSee('Cliente Atrasado')
            ->assertSee('Cliente Quase')
            ->assertSee('Ana Atendente')
            ->assertSee('Comercial')
            ->assertSee('SLA estourado')
            ->assertSee('Perto de estourar');
    }

    public function test_sem_ninguem_esperando_a_tela_diz_isso(): void
    {
        $this->supervisorPage()->assertSee('Ninguém esperando além do SLA agora');
    }

    public function test_aviso_quando_a_rotina_nao_roda_ha_mais_de_3_minutos(): void
    {
        $this->supervisorPage()->assertSee('O verificador de SLA não roda há mais de 3 minutos');

        Cache::put('sla:last-run', now()->subMinutes(4)->getTimestamp(), 600);
        $this->supervisorPage()->assertSee('O verificador de SLA não roda há mais de 3 minutos');

        Cache::put('sla:last-run', now()->subMinutes(2)->getTimestamp(), 600);
        $this->supervisorPage()->assertDontSee('O verificador de SLA não roda');
    }

    public function test_a_batida_que_o_redis_devolve_como_texto_conta_como_recente(): void
    {
        // O Redis guarda número sem serializar e o devolve como texto: o cache `array` dos testes devolve o `int` que entrou.
        Cache::put('sla:last-run', (string) now()->subMinutes(2)->getTimestamp(), 600);
        $this->supervisorPage()->assertDontSee('O verificador de SLA não roda');

        Cache::put('sla:last-run', (string) now()->subMinutes(4)->getTimestamp(), 600);
        $this->supervisorPage()->assertSee('O verificador de SLA não roda há mais de 3 minutos');
    }

    public function test_batida_ilegivel_conta_como_rotina_parada(): void
    {
        Cache::put('sla:last-run', 'lixo', 600);

        $this->supervisorPage()->assertSee('O verificador de SLA não roda há mais de 3 minutos');
    }

    public function test_so_supervisor_e_admin_veem(): void
    {
        Livewire::actingAs($this->agent)->test(Index::class)->assertForbidden();
        Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))->test(Index::class)->assertOk();
    }

    public function test_cliente_em_espera_leva_para_a_fila(): void
    {
        $this->waiting('Vencido', '10:05', '10:10');

        $html = $this->supervisorPage()->html();

        $this->assertMatchesRegularExpression('#<a href="[^"]*/inbox"[^>]*>\s*Vencido\s*</a>#', $html);
    }
}
