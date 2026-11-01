<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InboxChannelBadgeTest extends TestCase
{
    use RefreshDatabase;

    private function channel(string $name, string $session, string $phone, bool $active = true): Channel
    {
        return Channel::factory()->connected()->create(['name' => $name, 'waha_session' => $session, 'phone' => $phone, 'active' => $active]);
    }

    private function ticketOn(Channel $channel): Ticket
    {
        return Ticket::factory()->create(['channel_id' => $channel->id, 'contact_id' => Contact::factory()->create()->id, 'status' => TicketStatus::AGUARDANDO]);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::ADMIN]);
    }

    public function test_com_um_canal_ativo_o_numero_nao_aparece(): void
    {
        $channel = $this->channel('Comercial', 'a', '5516999990001');
        $this->channel('Antigo', 'b', '5516988887777', active: false);
        $ticket = $this->ticketOn($channel);

        Livewire::actingAs($this->admin())->test(Inbox::class)->call('select', $ticket->id)
            ->assertDontSee('(16) 99999-0001')->assertDontSee('(16) 98888-7777');
    }

    public function test_com_dois_canais_ativos_lista_e_cabecalho_mostram_nome_e_numero(): void
    {
        $first = $this->channel('Comercial', 'a', '5516999990001');
        $second = $this->channel('Suporte', 'b', '5516988887777');
        $this->ticketOn($first);
        $ticket = $this->ticketOn($second);

        $screen = Livewire::actingAs($this->admin())->test(Inbox::class);

        $screen->assertSee('Comercial · (16) 99999-0001')->assertSee('Suporte · (16) 98888-7777');

        $header = $screen->call('select', $ticket->id)->html();
        $this->assertStringContainsString($ticket->protocol.' ·', $header);
        // O Livewire põe um marcador de bloco dentro do @if do cabeçalho: a regex o tolera.
        $this->assertSame(3, preg_match_all('/Suporte(?:<!--\[if BLOCK\]><!\[endif\]-->)? · \(16\) 98888-7777/', $header), 'item da lista, cabeçalho e painel de detalhes');
    }
}
