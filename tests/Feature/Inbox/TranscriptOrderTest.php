<?php

namespace Tests\Feature\Inbox;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\History\Index as HistoryIndex;
use App\Livewire\Inbox\Inbox;
use App\Livewire\Inbox\Transcript;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** A conversa segue o horário de envio: mensagem reentregue depois de uma queda não pode aparecer fora de ordem. */
class TranscriptOrderTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);
    }

    public function test_conversa_ordena_pelo_horario_de_envio_e_nao_pela_chegada(): void
    {
        $ticket = Ticket::factory()->create();
        Message::factory()->for($ticket)->create(['body' => 'chegou primeiro enviada depois', 'sent_at' => now()->setTime(11, 47)]);
        Message::factory()->for($ticket)->create(['body' => 'chegou depois enviada antes', 'sent_at' => now()->setTime(11, 46)]);

        Livewire::actingAs($this->admin)->test(Transcript::class, ['ticketId' => $ticket->id])
            ->assertSeeInOrder(['chegou depois enviada antes', 'chegou primeiro enviada depois']);
    }

    public function test_as_200_mostradas_sao_as_de_maior_horario_de_envio(): void
    {
        $ticket = Ticket::factory()->create();
        foreach (range(1, 200) as $i) {
            Message::factory()->for($ticket)->create(['body' => 'normal '.$i, 'sent_at' => now()->subMinutes(500 - $i)]);
        }
        // A última a chegar (maior id), mas a mais antiga de todas.
        Message::factory()->for($ticket)->create(['body' => 'ANTIGA RECEBIDA POR ULTIMO', 'sent_at' => now()->subDays(2)]);

        Livewire::actingAs($this->admin)->test(Transcript::class, ['ticketId' => $ticket->id])
            ->assertDontSee('ANTIGA RECEBIDA POR ULTIMO')
            ->assertSee('normal 200');
    }

    public function test_historico_tambem_ordena_pelo_horario_de_envio(): void
    {
        $ticket = Ticket::factory()->create(['status' => TicketStatus::ENCERRADO, 'closed_at' => now()]);
        Message::factory()->for($ticket)->create(['body' => 'chegou primeiro enviada depois', 'sent_at' => now()->setTime(11, 47)]);
        Message::factory()->for($ticket)->create(['body' => 'chegou depois enviada antes', 'sent_at' => now()->setTime(11, 46)]);

        Livewire::actingAs($this->admin)->withQueryParams(['ticketId' => $ticket->id])->test(HistoryIndex::class)
            ->assertSeeInOrder(['chegou depois enviada antes', 'chegou primeiro enviada depois']);
    }

    public function test_previa_da_fila_mostra_a_ultima_por_horario_de_envio(): void
    {
        $ticket = Ticket::factory()->create(['status' => TicketStatus::AGUARDANDO]);
        Message::factory()->for($ticket)->create(['body' => 'enviada por ultimo chegou primeiro', 'sent_at' => now()->setTime(11, 47)]);
        Message::factory()->for($ticket)->create(['body' => 'enviada antes chegou depois', 'sent_at' => now()->setTime(11, 46)]);

        Livewire::actingAs($this->admin)->test(Inbox::class)
            ->assertSee('enviada por ultimo chegou primeiro')
            ->assertDontSee('enviada antes chegou depois');
    }

    public function test_previa_da_fila_marca_a_mensagem_apagada(): void
    {
        $ticket = Ticket::factory()->create(['status' => TicketStatus::AGUARDANDO]);
        Message::factory()->for($ticket)->create(['body' => 'texto que o cliente apagou', 'revoked_at' => now()]);

        Livewire::actingAs($this->admin)->test(Inbox::class)
            ->assertSee('Apagada · texto que o cliente apagou');
    }
}
