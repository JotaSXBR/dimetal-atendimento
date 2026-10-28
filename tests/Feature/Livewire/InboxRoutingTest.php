<?php

namespace Tests\Feature\Livewire;

use App\Enums\SectorStatus;
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

class InboxRoutingTest extends TestCase
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

    private function attendant(Sector $sector, bool $canTransfer = true, string $name = 'Atendente'): User
    {
        $user = User::factory()->create(['role' => UserRole::ATENDENTE, 'can_transfer' => $canTransfer, 'name' => $name]);
        $user->sectors()->attach($sector);

        return $user;
    }

    private function ticket(TicketStatus $status, ?Sector $sector, ?User $owner = null): Ticket
    {
        return Ticket::factory()->create([
            'channel_id' => $this->channel->id,
            'contact_id' => Contact::factory()->create(['name' => 'Maria Souza']),
            'status' => $status,
            'sector_id' => $sector?->id,
            'assigned_user_id' => $owner?->id,
        ]);
    }

    public function test_conversa_mostra_o_protocolo(): void
    {
        $me = $this->attendant($this->vendas);
        $ticket = $this->ticket(TicketStatus::AGUARDANDO, $this->vendas);

        Livewire::actingAs($me)->test(Inbox::class)
            ->call('select', $ticket->id)
            ->assertSee((string) $ticket->protocol);
    }

    public function test_responsavel_transfere_o_ticket_e_ele_some_da_tela_dele(): void
    {
        $me = $this->attendant($this->vendas);
        $ticket = $this->ticket(TicketStatus::EM_ATENDIMENTO, $this->vendas, $me);

        Livewire::actingAs($me)->test(Inbox::class)
            ->call('select', $ticket->id)
            ->call('openPanel', 'transfer')
            ->assertSee('Transferir para o setor')
            ->set('transferSectorId', $this->financeiro->id)
            ->set('transferNote', 'cliente quer boleto')
            ->call('transfer')
            ->assertSet('panel', null)
            ->assertDontSee('Maria Souza');

        $ticket->refresh();
        $this->assertSame($this->financeiro->id, $ticket->sector_id);
        $this->assertNull($ticket->assigned_user_id);
        $this->assertSame('cliente quer boleto', $ticket->events()->where('type', 'transferred')->sole()->payload['note']);
    }

    public function test_transferir_exige_setor_valido_que_receba_tickets(): void
    {
        $closed = Sector::factory()->create(['name' => 'Antigo', 'status' => SectorStatus::DESATIVADO]);
        $me = $this->attendant($this->vendas);
        $ticket = $this->ticket(TicketStatus::EM_ATENDIMENTO, $this->vendas, $me);

        Livewire::actingAs($me)->test(Inbox::class)
            ->call('select', $ticket->id)
            ->call('openPanel', 'transfer')
            ->call('transfer')
            ->assertHasErrors('transferSectorId')
            ->set('transferSectorId', $closed->id)
            ->call('transfer')
            ->assertHasErrors('transferSectorId');

        $this->assertSame($this->vendas->id, $ticket->fresh()?->sector_id);
    }

    public function test_atendente_com_a_opcao_desligada_nao_ve_o_botao_nem_consegue_transferir(): void
    {
        $blocked = $this->attendant($this->vendas, canTransfer: false);
        $ticket = $this->ticket(TicketStatus::EM_ATENDIMENTO, $this->vendas, $blocked);

        Livewire::actingAs($blocked)->test(Inbox::class)
            ->call('select', $ticket->id)
            ->assertDontSee('Transferir')
            ->call('openPanel', 'transfer')
            ->assertForbidden();
    }

    public function test_supervisor_reatribui_para_alguem_do_setor_do_ticket(): void
    {
        $supervisor = User::factory()->create(['role' => UserRole::SUPERVISOR]);
        $before = $this->attendant($this->vendas, name: 'Antes');
        $after = $this->attendant($this->vendas, name: 'Depois');
        $other = $this->attendant($this->financeiro, name: 'Outro Setor');
        $ticket = $this->ticket(TicketStatus::EM_ATENDIMENTO, $this->vendas, $before);

        Livewire::actingAs($supervisor)->test(Inbox::class)
            ->call('select', $ticket->id)
            ->call('openPanel', 'reassign')
            ->assertSee('Depois')
            ->assertDontSee('Outro Setor')
            ->set('reassignUserId', $after->id)
            ->call('reassign')
            ->assertSet('panel', null);

        $this->assertSame($after->id, $ticket->fresh()?->assigned_user_id);
        $this->assertNotSame($other->id, $ticket->fresh()?->assigned_user_id);
    }

    public function test_atendente_nao_reatribui(): void
    {
        $me = $this->attendant($this->vendas);
        $ticket = $this->ticket(TicketStatus::EM_ATENDIMENTO, $this->vendas, $me);

        Livewire::actingAs($me)->test(Inbox::class)
            ->call('select', $ticket->id)
            ->assertDontSee('Reatribuir')
            ->call('openPanel', 'reassign')
            ->assertForbidden();
    }

    public function test_atendente_toma_o_ticket_do_colega_do_mesmo_setor(): void
    {
        $colleague = $this->attendant($this->vendas, name: 'Colega');
        $me = $this->attendant($this->vendas);
        $ticket = $this->ticket(TicketStatus::EM_ATENDIMENTO, $this->vendas, $colleague);

        Livewire::actingAs($me)->test(Inbox::class)
            ->call('select', $ticket->id)
            ->assertSee('Tomar')
            ->call('takeover', $colleague->id)
            ->assertSet('notice', null);

        $this->assertSame($me->id, $ticket->fresh()?->assigned_user_id);
    }

    public function test_quem_toma_um_ticket_que_ja_mudou_de_mao_recebe_aviso(): void
    {
        $colleague = $this->attendant($this->vendas);
        $first = $this->attendant($this->vendas);
        $second = $this->attendant($this->vendas);
        $ticket = $this->ticket(TicketStatus::EM_ATENDIMENTO, $this->vendas, $colleague);

        $slow = Livewire::actingAs($second)->test(Inbox::class)->call('select', $ticket->id);
        Livewire::actingAs($first)->test(Inbox::class)->call('select', $ticket->id)->call('takeover', $colleague->id);

        // O usuário autenticado é global no teste: volta a ser quem demorou.
        $this->actingAs($second);
        $slow->call('takeover', $colleague->id)->assertSee('mudou de responsável');

        $this->assertSame($first->id, $ticket->fresh()?->assigned_user_id);
    }

    public function test_atendente_nao_toma_ticket_de_outro_setor_nem_sem_a_opcao(): void
    {
        $colleague = $this->attendant($this->financeiro);
        $ticket = $this->ticket(TicketStatus::EM_ATENDIMENTO, $this->financeiro, $colleague);
        $blocked = $this->attendant($this->vendas, canTransfer: false);
        $sameSector = $this->ticket(TicketStatus::EM_ATENDIMENTO, $this->vendas, $this->attendant($this->vendas));

        // Outro setor: nem enxerga o ticket.
        Livewire::actingAs($this->attendant($this->vendas))->test(Inbox::class)
            ->call('select', $ticket->id)
            ->assertForbidden();

        // Mesmo setor, mas com a opção desligada: vê, não toma.
        Livewire::actingAs($blocked)->test(Inbox::class)
            ->call('select', $sameSector->id)
            ->assertDontSee('Tomar')
            ->call('takeover', (int) $sameSector->assigned_user_id)
            ->assertForbidden();
    }
}
