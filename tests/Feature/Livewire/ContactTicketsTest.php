<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\ContactTickets;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class ContactTicketsTest extends TestCase
{
    use RefreshDatabase;

    private Contact $contact;

    private Channel $first;

    private Channel $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contact = Contact::factory()->create();
        $this->first = Channel::factory()->connected()->create(['name' => 'Comercial', 'waha_session' => 'a', 'phone' => '5516999990001']);
        $this->second = Channel::factory()->connected()->create(['name' => 'Suporte', 'waha_session' => 'b', 'phone' => '5516988887777']);
    }

    /** @param  array<string, mixed>  $attributes */
    private function ticket(Channel $channel, string $protocol, array $attributes = [], ?Contact $contact = null): Ticket
    {
        return Ticket::factory()->create([
            'channel_id' => $channel->id,
            'contact_id' => ($contact ?? $this->contact)->id,
            'protocol' => $protocol,
            'status' => TicketStatus::AGUARDANDO,
            ...$attributes,
        ]);
    }

    private function closed(Channel $channel, string $protocol, int $daysAgo, ?Contact $contact = null): Ticket
    {
        return $this->ticket($channel, $protocol, ['status' => TicketStatus::ENCERRADO, 'closed_at' => now()->subDays($daysAgo)], $contact);
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => UserRole::ADMIN]);
    }

    public function test_lista_os_outros_tickets_do_contato_abertos_antes_dos_encerrados_na_janela_de_30_dias(): void
    {
        $current = $this->ticket($this->first, '2026-000900');
        $this->closed($this->second, '2026-000901', 10);
        $this->ticket($this->second, '2026-000902', ['status' => TicketStatus::EM_ATENDIMENTO]);
        $this->closed($this->second, '2026-000903', 40);
        $this->ticket($this->second, '2026-000904', contact: Contact::factory()->create());

        Livewire::actingAs($this->admin())->test(ContactTickets::class, ['ticketId' => $current->id])
            ->assertSeeInOrder(['2026-000902', '2026-000901'])
            ->assertDontSee('2026-000900')
            ->assertDontSee('2026-000903')
            ->assertDontSee('2026-000904');
    }

    public function test_ticket_aberto_em_outro_numero_ganha_o_destaque(): void
    {
        $current = $this->ticket($this->first, '2026-000900');
        $this->ticket($this->second, '2026-000902', ['status' => TicketStatus::EM_ATENDIMENTO]);

        Livewire::actingAs($this->admin())->test(ContactTickets::class, ['ticketId' => $current->id])
            ->assertSee('também tem ticket aberto no número')
            ->assertSee('(16) 98888-7777');
    }

    public function test_quem_pode_ver_recebe_o_link_e_quem_nao_pode_nao(): void
    {
        $current = $this->ticket($this->first, '2026-000900');
        $sector = Sector::factory()->create(['name' => 'Setor Reservado']);
        $owner = User::factory()->create(['name' => 'Fulano Secreto', 'role' => UserRole::ATENDENTE]);
        $hidden = $this->ticket($this->second, '2026-000902', ['status' => TicketStatus::EM_ATENDIMENTO, 'sector_id' => $sector->id, 'assigned_user_id' => $owner->id]);
        Message::factory()->for($hidden)->create(['body' => 'texto sigiloso']);

        $html = Livewire::actingAs($this->admin())->test(ContactTickets::class, ['ticketId' => $current->id])->html();
        $this->assertStringContainsString('select('.$hidden->id.')', $html, 'admin vê o outro ticket e recebe o link');

        $attendant = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $screen = Livewire::actingAs($attendant)->test(ContactTickets::class, ['ticketId' => $current->id])
            ->assertSee('2026-000902')->assertSee('(16) 98888-7777')
            ->assertDontSee('Setor Reservado')->assertDontSee('Fulano Secreto')->assertDontSee('texto sigiloso');
        $this->assertStringNotContainsString('select('.$hidden->id.')', $screen->html(), 'sem acesso, sem link');
    }

    public function test_mostra_no_maximo_cinco_itens(): void
    {
        $current = $this->ticket($this->first, '2026-000900');
        foreach (range(1, 7) as $n) {
            $this->closed($this->second, sprintf('2026-0019%02d', $n), $n);
        }

        $html = Livewire::actingAs($this->admin())->test(ContactTickets::class, ['ticketId' => $current->id])->html();

        $this->assertSame(5, preg_match_all('/2026-0019\d\d/', $html));
    }

    public function test_sem_outros_tickets_nao_mostra_nada(): void
    {
        $current = $this->ticket($this->first, '2026-000900');

        Livewire::actingAs($this->admin())->test(ContactTickets::class, ['ticketId' => $current->id])
            ->assertDontSee('Outros tickets deste contato');
    }

    public function test_ticket_que_o_usuario_nao_pode_ver_nao_mostra_a_faixa(): void
    {
        $sector = Sector::factory()->create();
        $current = $this->ticket($this->first, '2026-000900', ['status' => TicketStatus::EM_ATENDIMENTO, 'sector_id' => $sector->id]);
        $this->closed($this->second, '2026-000901', 3);

        Livewire::actingAs(User::factory()->create(['role' => UserRole::ATENDENTE]))->test(ContactTickets::class, ['ticketId' => $current->id])
            ->assertDontSee('2026-000901');
    }

    public function test_digitar_barra_no_inbox_nao_consulta_a_faixa(): void
    {
        $me = User::factory()->create(['role' => UserRole::ADMIN, 'sign_messages' => false]);
        $current = $this->ticket($this->first, '2026-000900', ['status' => TicketStatus::EM_ATENDIMENTO, 'assigned_user_id' => $me->id]);
        $this->closed($this->second, '2026-000901', 3);
        $screen = Livewire::actingAs($me)->test(Inbox::class)->call('select', $current->id);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $screen->set('draft', '/');

        $queries = array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], "case when status = 'encerrado'"));
        $this->assertSame([], $queries);
    }
}
