<?php

namespace Tests\Feature\Livewire;

use App\Enums\SectorStatus;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Settings\Sectors;
use App\Models\Channel;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SectorsPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::ADMIN]);
    }

    /** @return array<string, array{UserRole}> */
    public static function papeisSemAcesso(): array
    {
        return ['supervisor' => [UserRole::SUPERVISOR], 'atendente' => [UserRole::ATENDENTE]];
    }

    #[DataProvider('papeisSemAcesso')]
    public function test_so_admin_entra(UserRole $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]))->get('/configuracoes/setores')->assertForbidden();
    }

    #[DataProvider('papeisSemAcesso')]
    public function test_quem_nao_e_admin_nao_muda_nada_nem_forjando_a_chamada(UserRole $role): void
    {
        $sector = Sector::factory()->create(['menu_order' => 1]);

        Livewire::actingAs(User::factory()->create(['role' => $role]))->test(Sectors::class)->assertForbidden();

        $this->assertSame(SectorStatus::ATIVO, $sector->fresh()?->status);
    }

    public function test_admin_lista_os_setores_na_ordem_do_menu(): void
    {
        Sector::factory()->create(['name' => 'Segundo', 'menu_order' => 2]);
        Sector::factory()->create(['name' => 'Primeiro', 'menu_order' => 1]);

        Livewire::actingAs($this->admin())->test(Sectors::class)->assertSeeInOrder(['Primeiro', 'Segundo']);
    }

    public function test_admin_troca_o_estado_do_setor(): void
    {
        $sector = Sector::factory()->create(['menu_order' => 1]);

        Livewire::actingAs($this->admin())->test(Sectors::class)
            ->call('setStatus', $sector->id, 'fora_do_menu')
            ->assertHasNoErrors();

        $this->assertSame(SectorStatus::FORA_DO_MENU, $sector->fresh()?->status);
    }

    public function test_desativar_setor_com_ticket_aberto_e_bloqueado_mas_encerrado_nao_impede(): void
    {
        $sector = Sector::factory()->create(['menu_order' => 1]);
        $channel = Channel::factory()->create();
        $open = Ticket::factory()->create(['channel_id' => $channel->id, 'sector_id' => $sector->id, 'status' => TicketStatus::AGUARDANDO]);

        $component = Livewire::actingAs($this->admin())->test(Sectors::class)
            ->call('setStatus', $sector->id, 'desativado')
            ->assertHasErrors('status.'.$sector->id);

        $this->assertSame(SectorStatus::ATIVO, $sector->fresh()?->status);

        $open->update(['status' => TicketStatus::ENCERRADO]);
        $component->call('setStatus', $sector->id, 'desativado')->assertHasNoErrors();

        $this->assertSame(SectorStatus::DESATIVADO, $sector->fresh()?->status);
    }

    public function test_reativar_setor_desativado(): void
    {
        $sector = Sector::factory()->create(['menu_order' => 1, 'status' => SectorStatus::DESATIVADO]);

        Livewire::actingAs($this->admin())->test(Sectors::class)->call('setStatus', $sector->id, 'ativo');

        $this->assertSame(SectorStatus::ATIVO, $sector->fresh()?->status);
    }

    public function test_estado_invalido_e_recusado(): void
    {
        $sector = Sector::factory()->create(['menu_order' => 1]);

        Livewire::actingAs($this->admin())->test(Sectors::class)
            ->call('setStatus', $sector->id, 'apagado')
            ->assertHasErrors('status.'.$sector->id);

        $this->assertSame(SectorStatus::ATIVO, $sector->fresh()?->status);
    }

    public function test_mover_troca_a_ordem_do_menu_e_deixa_a_sequencia_continua(): void
    {
        $a = Sector::factory()->create(['name' => 'A', 'menu_order' => 1]);
        $b = Sector::factory()->create(['name' => 'B', 'menu_order' => 5]);
        $c = Sector::factory()->create(['name' => 'C', 'menu_order' => 9]);

        Livewire::actingAs($this->admin())->test(Sectors::class)
            ->call('move', $c->id, 'up')
            ->call('move', $a->id, 'up');

        $this->assertSame(['A', 'C', 'B'], Sector::query()->orderBy('menu_order')->pluck('name')->all());
        $this->assertSame([1, 2, 3], Sector::query()->orderBy('menu_order')->pluck('menu_order')->all());
        $this->assertNotNull($b->fresh());
    }

    public function test_mover_para_baixo_e_no_ultimo_nao_faz_nada(): void
    {
        Sector::factory()->create(['name' => 'A', 'menu_order' => 1]);
        $last = Sector::factory()->create(['name' => 'B', 'menu_order' => 2]);

        Livewire::actingAs($this->admin())->test(Sectors::class)->call('move', $last->id, 'down');

        $this->assertSame(['A', 'B'], Sector::query()->orderBy('menu_order')->pluck('name')->all());
    }

    public function test_mudar_estado_e_ordem_nao_altera_tickets_existentes(): void
    {
        $sector = Sector::factory()->create(['menu_order' => 1]);
        $ticket = Ticket::factory()->create(['sector_id' => $sector->id, 'status' => TicketStatus::AGUARDANDO]);

        Livewire::actingAs($this->admin())->test(Sectors::class)->call('setStatus', $sector->id, 'fora_do_menu');

        $this->assertSame($sector->id, $ticket->fresh()?->sector_id);
        $this->assertSame(TicketStatus::AGUARDANDO, $ticket->fresh()?->status);
    }
}
