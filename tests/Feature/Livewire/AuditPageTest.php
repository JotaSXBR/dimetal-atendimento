<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Audit\Index;
use App\Models\Channel;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class AuditPageTest extends TestCase
{
    use RefreshDatabase;

    private ?Channel $channel = null;

    private function page(UserRole $role = UserRole::ADMIN): Testable
    {
        return Livewire::actingAs(User::factory()->create(['role' => $role]))->test(Index::class);
    }

    private function event(string $type, ?User $actor = null, ?array $payload = null, ?CarbonImmutable $at = null, ?Ticket $ticket = null): TicketEvent
    {
        $event = TicketEvent::create([
            'ticket_id' => ($ticket ?? Ticket::factory()->for($this->channel ??= Channel::factory()->create())->create())->id,
            'type' => $type,
            'actor_user_id' => $actor?->id,
            'payload' => $payload,
        ]);
        $event->forceFill(['created_at' => $at ?? now()])->save();

        return $event;
    }

    public function test_so_admin_entra(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::SUPERVISOR]))->get('/auditoria')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::ATENDENTE]))->get('/auditoria')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::ADMIN]))->get('/auditoria')->assertOk();
    }

    public function test_lista_com_protocolo_usuario_acao_e_detalhe_do_mais_novo_ao_mais_velho(): void
    {
        $ana = User::factory()->create(['name' => 'Ana Atendente']);
        $this->event('closed', $ana, ['reason' => 'manual', 'outcome' => 'resolvido', 'summary' => 'Cotacao enviada'], now()->subHour());
        $this->event('claimed', $ana, null, now());

        $this->page()->assertSeeInOrder(['Assumido', 'Encerrado'])
            ->assertSee('Ana Atendente')->assertSee('2026-000')
            ->assertSee('Cotacao enviada')->assertSee('Resolvido');
    }

    public function test_filtra_por_tipo_usuario_e_periodo(): void
    {
        $ana = User::factory()->create(['name' => 'Ana Atendente']);
        $bia = User::factory()->create(['name' => 'Bia Atendente']);
        $alfa = Sector::factory()->create(['name' => 'Setor Alfa']);
        $velho = Sector::factory()->create(['name' => 'Setor Velho']);
        $this->event('transferred', $ana, ['to_sector' => $alfa->id], now());
        $this->event('closed', $bia, ['reason' => 'manual', 'summary' => 'Resumo da Bia'], now());
        $this->event('transferred', $ana, ['to_sector' => $velho->id], now()->subDays(10));

        $this->page()->set('type', 'closed')->assertSee('Resumo da Bia')->assertDontSee('Setor Alfa');
        $this->page()->set('userId', $ana->id)->assertDontSee('Resumo da Bia')->assertSee('Setor Alfa')->assertDontSee('Setor Velho');
        $this->page()->set('period', '30')->assertSee('Setor Velho');
    }

    public function test_horario_em_sao_paulo_e_hoje_comeca_a_meia_noite_de_la(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-31 01:00:00'));
        $hoje = Sector::factory()->create(['name' => 'Setor de Hoje']);
        $ontem = Sector::factory()->create(['name' => 'Setor de Ontem']);
        $this->event('transferred', null, ['to_sector' => $hoje->id], CarbonImmutable::parse('2026-10-30 12:00:00'));
        $this->event('transferred', null, ['to_sector' => $ontem->id], CarbonImmutable::parse('2026-10-30 02:00:00'));

        $this->page()->set('period', 'hoje')->assertSee('Setor de Hoje')->assertDontSee('Setor de Ontem')->assertSee('30/10 09:00');
    }

    public function test_pagina_de_25_em_25(): void
    {
        $ticket = Ticket::factory()->for($this->channel ??= Channel::factory()->create())->create();
        foreach (range(1, 30) as $n) {
            $this->event('tipo-novo', null, ['n' => $n], now()->subMinutes($n), $ticket);
        }

        // Tipo que a tela não conhece: a auditoria mostra o conteúdo como veio.
        $this->page()->assertSee('{"n":1}')->assertDontSee('{"n":30}');
    }

    public function test_evento_de_sistema_aparece_como_sistema(): void
    {
        $this->event('closed', null, ['reason' => 'inatividade']);

        $this->page()->assertSee('Sistema')->assertSee('por inatividade');
    }

    public function test_protocolo_de_ticket_encerrado_leva_ao_historico_e_o_de_aberto_a_fila(): void
    {
        $channel = $this->channel ??= Channel::factory()->create();
        $closed = Ticket::factory()->for($channel)->create(['status' => TicketStatus::ENCERRADO, 'closed_at' => now(), 'protocol' => '2026-000901']);
        $open = Ticket::factory()->for($channel)->create(['status' => TicketStatus::AGUARDANDO, 'protocol' => '2026-000902']);
        $this->event('closed', null, null, null, $closed);
        $this->event('claimed', null, null, null, $open);

        $html = $this->page()->html();

        $this->assertMatchesRegularExpression('#<a href="[^"]*/historico\?ticketId='.$closed->id.'"[^>]*>\s*2026-000901\s*</a>#', $html);
        $this->assertMatchesRegularExpression('#<a href="[^"]*/inbox"[^>]*>\s*2026-000902\s*</a>#', $html);
    }
}
