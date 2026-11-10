<?php

namespace Tests\Feature\Domain;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\History\Index as HistoryIndex;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SectorSoftDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_ticket_antigo_continua_mostrando_o_nome_do_setor_apagado(): void
    {
        $sector = Sector::factory()->create(['name' => 'SAC Antigo']);
        $ticket = Ticket::factory()->create(['sector_id' => $sector->id, 'status' => TicketStatus::ENCERRADO]);

        $sector->delete();

        $fresh = $ticket->fresh();
        $this->assertSame($sector->id, $fresh?->sector_id);
        $this->assertSame('SAC Antigo', $fresh?->sector?->name);
    }

    public function test_pode_recriar_um_setor_com_o_nome_de_um_apagado(): void
    {
        $old = Sector::factory()->create(['name' => 'Logística']);
        $old->delete();

        $new = Sector::factory()->create(['name' => 'Logística']);

        $this->assertNotSame($old->id, $new->id);
        $this->assertSame(1, Sector::query()->where('name', 'Logística')->count());
    }

    public function test_nome_duplicado_entre_ativos_viola_o_indice(): void
    {
        Sector::factory()->create(['name' => 'Logística']);

        $this->expectException(UniqueConstraintViolationException::class);

        Sector::factory()->create(['name' => 'Logística']);
    }

    public function test_setor_apagado_some_do_menu_da_transferencia_e_das_buscas(): void
    {
        $sector = Sector::factory()->create();
        $sector->delete();

        $this->assertNull(Sector::query()->find($sector->id));
        $this->assertSame(0, Sector::query()->visibleInMenu()->count());
        $this->assertSame(0, Sector::query()->receivingTickets()->count());
    }

    public function test_historico_ainda_filtra_pelo_setor_apagado_que_teve_ticket(): void
    {
        $sector = Sector::factory()->create(['name' => 'Setor Fechado']);
        $unused = Sector::factory()->create(['name' => 'Setor Sem Uso']);
        Ticket::factory()->create(['sector_id' => $sector->id, 'status' => TicketStatus::ENCERRADO, 'closed_at' => now()]);
        $sector->delete();
        $unused->delete();

        Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))->test(HistoryIndex::class)
            ->assertSee('Setor Fechado')
            ->assertDontSee('Setor Sem Uso');
    }
}
