<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InboxPendingTest extends TestCase
{
    use RefreshDatabase;

    private Channel $channel;

    private Sector $vendas;

    private Sector $financeiro;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = Channel::factory()->create();
        $this->vendas = Sector::factory()->create(['name' => 'Vendas', 'menu_order' => 1]);
        $this->financeiro = Sector::factory()->create(['name' => 'Financeiro', 'menu_order' => 2]);
    }

    private function attendant(Sector $sector): User
    {
        $user = User::factory()->create(['role' => UserRole::ATENDENTE, 'can_transfer' => false]);
        $user->sectors()->attach($sector);

        return $user;
    }

    private function ticket(TicketStatus $status, string $name): Ticket
    {
        return Ticket::factory()->create([
            'channel_id' => $this->channel->id,
            'contact_id' => Contact::factory()->create(['name' => $name]),
            'status' => $status,
        ]);
    }

    public function test_aba_pendentes_lista_so_pendentes_para_todos(): void
    {
        $this->ticket(TicketStatus::PENDENTE, 'Cliente no Bot');
        $this->ticket(TicketStatus::AGUARDANDO, 'Cliente na Fila');
        $me = $this->attendant($this->financeiro);

        Livewire::actingAs($me)->test(Inbox::class, ['tab' => 'pendentes'])
            ->assertSee('Cliente no Bot')
            ->assertDontSee('Cliente na Fila');

        Livewire::actingAs($me)->test(Inbox::class, ['tab' => 'aguardando'])
            ->assertDontSee('Cliente no Bot')
            ->assertSee('Cliente na Fila');
    }

    public function test_pendente_abre_sem_caixa_de_resposta_e_com_transferir(): void
    {
        $ticket = $this->ticket(TicketStatus::PENDENTE, 'Cliente no Bot');
        $me = $this->attendant($this->financeiro);

        Livewire::actingAs($me)->test(Inbox::class)
            ->call('select', $ticket->id)
            ->assertSee('Em triagem automática')
            ->assertSee('Transferir')
            ->assertDontSee('Assumir')
            ->assertViewHas('canReply', false)
            ->assertViewHas('canClaim', false)
            ->assertViewHas('canTransfer', true);
    }

    public function test_transferir_pelo_painel_move_para_aguardando_no_setor(): void
    {
        $ticket = $this->ticket(TicketStatus::PENDENTE, 'Cliente no Bot');
        $me = $this->attendant($this->financeiro);

        Livewire::actingAs($me)->test(Inbox::class)
            ->call('select', $ticket->id)
            ->call('openPanel', 'transfer')
            ->set('transferSectorId', $this->vendas->id)
            ->call('transfer')
            ->assertHasNoErrors();

        $fresh = $ticket->fresh();
        $this->assertSame(TicketStatus::AGUARDANDO, $fresh?->status);
        $this->assertSame($this->vendas->id, $fresh?->sector_id);
    }
}
