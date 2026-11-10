<?php

namespace Tests\Feature\History;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\History\Index;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HistoryContactFilterTest extends TestCase
{
    use RefreshDatabase;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = Channel::factory()->create();
    }

    private function closed(string $name, array $override = []): Ticket
    {
        return Ticket::factory()->create(array_replace([
            'channel_id' => $this->channel->id,
            'contact_id' => Contact::factory()->create(['name' => $name])->id,
            'status' => TicketStatus::ENCERRADO,
            'closed_at' => now(),
        ], $override));
    }

    public function test_url_com_contact_id_mostra_so_o_contato_e_o_selo(): void
    {
        $a = $this->closed('Ana Lima');
        $b = $this->closed('Bruno Reis');

        Livewire::actingAs(User::factory()->create(['role' => UserRole::SUPERVISOR]))
            ->withQueryParams(['contactId' => $a->contact_id])
            ->test(Index::class)
            ->assertSee('Contato: Ana Lima')
            ->assertSee($a->protocol)
            ->assertDontSee($b->protocol);
    }

    public function test_contato_de_ticket_que_o_atendente_nao_ve_fica_vazio(): void
    {
        $a = $this->closed('Ana Lima', ['sector_id' => Sector::factory()->create()->id]);

        Livewire::actingAs(User::factory()->create(['role' => UserRole::ATENDENTE]))
            ->withQueryParams(['contactId' => $a->contact_id])
            ->test(Index::class)
            ->assertDontSee($a->protocol);
    }

    public function test_remover_o_filtro_volta_a_lista_toda(): void
    {
        $a = $this->closed('Ana Lima');
        $b = $this->closed('Bruno Reis');

        Livewire::actingAs(User::factory()->create(['role' => UserRole::SUPERVISOR]))
            ->withQueryParams(['contactId' => $a->contact_id])
            ->test(Index::class)
            ->set('contactId', null)
            ->assertSee($a->protocol)
            ->assertSee($b->protocol)
            ->assertDontSee('Contato: Ana Lima');
    }

    /** "Ver todos" do cartão do contato serve justamente para os antigos: sem a janela padrão de 30 dias. */
    public function test_com_contato_nao_aplica_a_janela_padrao_de_30_dias(): void
    {
        $old = $this->closed('Ana Lima', ['closed_at' => now()->subDays(90)]);

        Livewire::actingAs(User::factory()->create(['role' => UserRole::SUPERVISOR]))
            ->withQueryParams(['contactId' => $old->contact_id])
            ->test(Index::class)
            ->assertSet('from', '')
            ->assertSee($old->protocol);
    }
}
