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

class InboxGoBackTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(string $protocol): Ticket
    {
        return Ticket::factory()->create([
            'channel_id' => Channel::query()->first()?->id ?? Channel::factory()->connected()->create()->id,
            'contact_id' => Contact::factory()->create()->id,
            'protocol' => $protocol,
            'status' => TicketStatus::AGUARDANDO,
        ]);
    }

    public function test_depois_de_abrir_outro_ticket_o_botao_volta_ao_anterior(): void
    {
        $first = $this->ticket('2026-000801');
        $second = $this->ticket('2026-000802');

        $screen = Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))->test(Inbox::class)
            ->call('select', $first->id)->assertDontSee('Voltar ao ticket anterior')
            ->call('select', $second->id)->assertSee('Voltar ao ticket anterior (2026-000801)');

        $screen->call('goBack')->assertSet('ticketId', $first->id)->assertSet('previousTicketId', null)
            ->assertDontSee('Voltar ao ticket anterior');
    }

    public function test_link_com_ticket_na_url_abre_o_ticket_que_o_usuario_pode_ver(): void
    {
        $ticket = $this->ticket('2026-000811');

        Livewire::withQueryParams(['ticket' => $ticket->id])
            ->actingAs(User::factory()->create(['role' => UserRole::ADMIN]))->test(Inbox::class)
            ->assertSet('ticketId', $ticket->id);
    }

    public function test_ticket_da_url_de_outro_setor_nao_abre(): void
    {
        $ticket = Ticket::factory()->create([
            'channel_id' => Channel::factory()->connected()->create()->id,
            'contact_id' => Contact::factory()->create()->id,
            'sector_id' => Sector::factory()->create()->id,
            'status' => TicketStatus::AGUARDANDO,
        ]);

        Livewire::withQueryParams(['ticket' => $ticket->id])
            ->actingAs(User::factory()->create(['role' => UserRole::ATENDENTE]))->test(Inbox::class)
            ->assertSet('ticketId', null);
    }

    public function test_coluna_do_meio_nao_repete_os_outros_tickets_do_contato(): void
    {
        $contact = Contact::factory()->create();
        $channel = Channel::factory()->connected()->create();
        $first = Ticket::factory()->create(['channel_id' => $channel->id, 'contact_id' => $contact->id, 'status' => TicketStatus::ENCERRADO, 'closed_at' => now()->subDay()]);
        $second = Ticket::factory()->create(['channel_id' => $channel->id, 'contact_id' => $contact->id, 'status' => TicketStatus::AGUARDANDO]);

        Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))->test(Inbox::class)
            ->call('select', $second->id)->assertDontSee('Outros tickets deste contato')->assertSee((string) $first->protocol);
    }

    public function test_selecionar_o_mesmo_ticket_nao_cria_anterior(): void
    {
        $first = $this->ticket('2026-000801');

        Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))->test(Inbox::class)
            ->call('select', $first->id)->call('select', $first->id)->assertSet('previousTicketId', null);
    }

    public function test_anterior_que_perdeu_o_acesso_nao_volta(): void
    {
        $attendant = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $first = $this->ticket('2026-000801');
        $second = $this->ticket('2026-000802');

        $screen = Livewire::actingAs($attendant)->test(Inbox::class)->call('select', $first->id)->call('select', $second->id);

        // Enquanto o atendente olhava o outro, o primeiro foi transferido para um setor que ele não atende.
        $first->update(['status' => TicketStatus::EM_ATENDIMENTO, 'sector_id' => Sector::factory()->create()->id, 'assigned_user_id' => User::factory()->create()->id]);

        $screen->call('$refresh')->assertDontSee('Voltar ao ticket anterior')->call('goBack')
            ->assertSet('ticketId', $second->id)->assertSet('previousTicketId', null);
    }

    public function test_anterior_forjado_pelo_cliente_nao_abre_ticket_sem_acesso(): void
    {
        $attendant = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $mine = $this->ticket('2026-000802');
        $hidden = $this->ticket('2026-000803');
        $hidden->update(['status' => TicketStatus::EM_ATENDIMENTO, 'sector_id' => Sector::factory()->create()->id, 'assigned_user_id' => User::factory()->create()->id]);

        Livewire::actingAs($attendant)->test(Inbox::class)->call('select', $mine->id)
            ->set('previousTicketId', $hidden->id)->call('goBack')
            ->assertSet('ticketId', $mine->id)->assertSet('previousTicketId', null)->assertDontSee('2026-000803');
    }
}
