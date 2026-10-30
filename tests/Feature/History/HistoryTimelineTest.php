<?php

namespace Tests\Feature\History;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Audit\Index as AuditIndex;
use App\Livewire\History\Index;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HistoryTimelineTest extends TestCase
{
    use RefreshDatabase;

    private Channel $channel;

    private Sector $sector;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = Channel::factory()->create();
        $this->sector = Sector::factory()->create(['name' => 'Comercial']);
        $this->agent = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $this->agent->sectors()->attach($this->sector);
    }

    private function closed(string $name, ?Sector $sector = null): Ticket
    {
        return Ticket::factory()->create([
            'channel_id' => $this->channel->id,
            'contact_id' => Contact::factory()->create(['name' => $name])->id,
            'status' => TicketStatus::ENCERRADO,
            'closed_at' => now()->subHour(),
            'sector_id' => ($sector ?? $this->sector)->id,
        ]);
    }

    public function test_historico_so_lista_o_que_mudou_o_ticket_em_frases(): void
    {
        $ticket = $this->closed('Joaquim');
        $financeiro = Sector::factory()->create(['name' => 'Financeiro']);
        TicketEvent::create(['ticket_id' => $ticket->id, 'type' => 'message_in', 'payload' => ['message_id' => 55]]);
        TicketEvent::create(['ticket_id' => $ticket->id, 'type' => 'message_out', 'actor_user_id' => $this->agent->id, 'payload' => ['message_id' => 56]]);
        TicketEvent::create(['ticket_id' => $ticket->id, 'type' => 'transferred', 'actor_user_id' => $this->agent->id, 'payload' => ['from_sector' => $this->sector->id, 'to_sector' => $financeiro->id]]);

        Livewire::actingAs($this->agent)->test(Index::class)->call('select', $ticket->id)
            ->assertSee('transferiu de Comercial para Financeiro')
            ->assertDontSee('Mensagem enviada')
            ->assertDontSee('Mensagem recebida')
            ->assertDontSee('message_id');
    }

    public function test_auditoria_mantem_todos_os_eventos_sem_json(): void
    {
        $ticket = $this->closed('Joaquim');
        TicketEvent::create(['ticket_id' => $ticket->id, 'type' => 'message_in', 'payload' => ['message_id' => 55]]);
        TicketEvent::create(['ticket_id' => $ticket->id, 'type' => 'message_out', 'actor_user_id' => $this->agent->id, 'payload' => ['message_id' => 56]]);

        Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))->test(AuditIndex::class)
            ->assertSee('Mensagem recebida')
            ->assertSee('Mensagem enviada')
            ->assertDontSee('message_id');
    }

    public function test_ticket_pela_url_abre_quando_o_usuario_pode_ver(): void
    {
        $ticket = $this->closed('Cliente Da Url');

        Livewire::actingAs($this->agent)->withQueryParams(['ticketId' => $ticket->id])->test(Index::class)
            ->assertSet('ticketId', $ticket->id)
            ->assertSee('Cliente Da Url');
    }

    public function test_ticket_pela_url_de_outro_setor_nao_abre(): void
    {
        $foreign = $this->closed('Cliente Alheio', Sector::factory()->create(['name' => 'Outro']));

        Livewire::actingAs($this->agent)->withQueryParams(['ticketId' => $foreign->id])->test(Index::class)
            ->assertDontSee('Cliente Alheio');
    }

    public function test_ticket_aberto_pela_url_nao_abre(): void
    {
        $open = Ticket::factory()->create([
            'channel_id' => $this->channel->id,
            'contact_id' => Contact::factory()->create(['name' => 'Cliente Aberto'])->id,
            'status' => TicketStatus::AGUARDANDO,
            'sector_id' => $this->sector->id,
        ]);

        Livewire::actingAs($this->agent)->withQueryParams(['ticketId' => $open->id])->test(Index::class)
            ->assertDontSee('Cliente Aberto');
    }
}
