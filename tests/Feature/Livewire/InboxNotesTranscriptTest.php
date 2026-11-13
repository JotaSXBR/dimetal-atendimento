<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Transcript;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InboxNotesTranscriptTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: Ticket} */
    private function mine(): array
    {
        $me = User::factory()->create(['role' => UserRole::ATENDENTE, 'name' => 'Ana Atendente']);
        $ticket = Ticket::factory()->create(['channel_id' => Channel::factory()->create()->id, 'status' => TicketStatus::EM_ATENDIMENTO, 'assigned_user_id' => $me->id]);

        return [$me, $ticket];
    }

    public function test_nota_aparece_entre_as_mensagens_na_ordem_do_horario(): void
    {
        [$me, $ticket] = $this->mine();
        Message::factory()->for($ticket)->create(['body' => 'primeira mensagem', 'sent_at' => now()->subMinutes(20)]);
        TicketNote::factory()->for($ticket)->create(['user_id' => $me->id, 'body' => 'nota do meio', 'created_at' => now()->subMinutes(10)]);
        Message::factory()->for($ticket)->create(['body' => 'ultima mensagem', 'sent_at' => now()->subMinutes(1)]);

        Livewire::actingAs($me)->test(Transcript::class, ['ticketId' => $ticket->id])
            ->assertSeeInOrder(['primeira mensagem', 'nota do meio', 'ultima mensagem']);
    }

    public function test_cartao_da_nota_mostra_rotulo_autor_e_hora_sem_tiques(): void
    {
        [$me, $ticket] = $this->mine();
        TicketNote::factory()->for($ticket)->create(['user_id' => $me->id, 'body' => 'so a equipe']);

        $html = Livewire::actingAs($me)->test(Transcript::class, ['ticketId' => $ticket->id])
            ->assertSeeHtml('data-testid="note-card"')
            ->assertSee('Nota interna')
            ->assertSee('Ana Atendente')
            ->assertSee('so a equipe')
            ->assertDontSeeHtml('data-testid="message-time"')
            ->html();

        $this->assertStringContainsString('border-warn-line', $html);
    }

    public function test_nota_do_bot_mostra_o_rotulo_da_origem_e_nao_tem_apagar(): void
    {
        [$me, $ticket] = $this->mine();
        TicketNote::factory()->for($ticket)->bot()->create(['body' => 'CNPJ coletado']);

        Livewire::actingAs($me)->test(Transcript::class, ['ticketId' => $ticket->id])
            ->assertSee('Nota interna · Bot')
            ->assertSee('CNPJ coletado')
            ->assertDontSee('Usuário removido')
            ->assertDontSeeHtml('deleteNote(');
    }

    public function test_apagar_so_aparece_para_o_autor(): void
    {
        [$me, $ticket] = $this->mine();
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        TicketNote::factory()->for($ticket)->create(['user_id' => $me->id]);

        Livewire::actingAs($me)->test(Transcript::class, ['ticketId' => $ticket->id])->assertSeeHtml('deleteNote(');
        Livewire::actingAs($admin)->test(Transcript::class, ['ticketId' => $ticket->id])->assertSee('Nota interna')->assertDontSeeHtml('deleteNote(');
    }

    public function test_partial_sem_notas_nao_mostra_cartao(): void
    {
        [$me, $ticket] = $this->mine();
        $message = Message::factory()->for($ticket)->create();

        $html = view('livewire.inbox.transcript', ['messages' => collect([$message->load(['reactions', 'edits', 'ticket.channel', 'contact'])]), 'readonly' => true])->render();

        $this->assertStringNotContainsString('note-card', $html);
    }
}
