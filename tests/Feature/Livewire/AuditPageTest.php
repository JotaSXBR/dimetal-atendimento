<?php

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Audit\Index;
use App\Models\Channel;
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
        $this->event('claimed', $ana, ['marca' => 'assumiu-ana'], now());
        $this->event('closed', $bia, ['reason' => 'manual', 'summary' => 'Resumo da Bia'], now());
        $this->event('transferred', $ana, ['marca' => 'transferiu-velho'], now()->subDays(10));

        $this->page()->set('type', 'closed')->assertSee('Resumo da Bia')->assertDontSee('assumiu-ana');
        $this->page()->set('userId', $ana->id)->assertDontSee('Resumo da Bia')->assertSee('assumiu-ana')->assertDontSee('transferiu-velho');
        $this->page()->set('period', '30')->assertSee('transferiu-velho');
    }

    public function test_pagina_de_25_em_25(): void
    {
        $ticket = Ticket::factory()->for($this->channel ??= Channel::factory()->create())->create();
        foreach (range(1, 30) as $n) {
            $this->event('claimed', null, ['n' => $n], now()->subMinutes($n), $ticket);
        }

        $this->page()->assertSee('{"n":1}')->assertDontSee('{"n":30}');
    }

    public function test_evento_de_sistema_aparece_como_sistema(): void
    {
        $this->event('closed', null, ['reason' => 'inatividade']);

        $this->page()->assertSee('Sistema')->assertSee('inatividade');
    }
}
