<?php

namespace Tests\Feature\Inbox;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Jobs\SendTicketMessageJob;
use App\Livewire\Inbox\Inbox;
use App\Livewire\Inbox\Transcript;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\TicketNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class SaveNoteTest extends TestCase
{
    use RefreshDatabase;

    private ?Channel $channel = null;

    private function ticket(User $owner, TicketStatus $status = TicketStatus::EM_ATENDIMENTO): Ticket
    {
        return Ticket::factory()->create(['channel_id' => ($this->channel ??= Channel::factory()->create())->id, 'status' => $status, 'assigned_user_id' => $owner->id]);
    }

    public function test_salvar_nota_grava_limpa_o_campo_e_nao_envia_nada(): void
    {
        Queue::fake();
        Http::fake();
        $me = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $ticket = $this->ticket($me);

        Livewire::actingAs($me)->test(Inbox::class)->call('select', $ticket->id)
            ->set('draft', 'combinado X')
            ->call('saveNote')
            ->assertSet('draft', '')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('ticket_notes', ['ticket_id' => $ticket->id, 'user_id' => $me->id, 'body' => 'combinado X']);
        $this->assertSame(0, Message::query()->count());
        $this->assertNull($ticket->fresh()->last_outbound_at);
        Queue::assertNotPushed(SendTicketMessageJob::class);
        Http::assertNothingSent();
    }

    public function test_nota_vazia_ou_longa_demais_e_recusada(): void
    {
        $me = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $ticket = $this->ticket($me);
        $screen = Livewire::actingAs($me)->test(Inbox::class)->call('select', $ticket->id);

        $screen->set('draft', '')->call('saveNote')->assertHasErrors('draft');
        $screen->set('draft', str_repeat('a', 4097))->call('saveNote')->assertHasErrors('draft');

        $this->assertSame(0, TicketNote::query()->count());
    }

    public function test_nota_em_ticket_encerrado_ou_de_outro_atendente_e_recusada(): void
    {
        $me = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $outro = User::factory()->create(['role' => UserRole::ATENDENTE]);

        $encerrado = $this->ticket($me, TicketStatus::ENCERRADO);
        Livewire::actingAs($me)->test(Inbox::class)->call('select', $encerrado->id)->set('draft', 'x')->call('saveNote')->assertForbidden();

        $alheio = $this->ticket($outro);
        Livewire::actingAs($me)->test(Inbox::class)->set('ticketId', $alheio->id)->set('draft', 'x')->call('saveNote')->assertStatus(404);

        $this->assertSame(0, TicketNote::query()->count());
    }

    public function test_so_o_autor_apaga_pelo_transcript(): void
    {
        $autor = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $ticket = $this->ticket($autor);
        $note = TicketNote::factory()->for($ticket)->create(['user_id' => $autor->id]);
        $outraDoutroTicket = TicketNote::factory()->for($this->ticket($autor))->create(['user_id' => $autor->id]);

        Livewire::actingAs($admin)->test(Transcript::class, ['ticketId' => $ticket->id])->call('deleteNote', $note->id)->assertForbidden();
        $this->assertDatabaseHas('ticket_notes', ['id' => $note->id]);

        $screen = Livewire::actingAs($autor)->test(Transcript::class, ['ticketId' => $ticket->id]);
        $screen->call('deleteNote', $outraDoutroTicket->id);
        $this->assertDatabaseHas('ticket_notes', ['id' => $outraDoutroTicket->id]);

        $screen->call('deleteNote', $note->id);
        $this->assertDatabaseMissing('ticket_notes', ['id' => $note->id]);
        $this->assertTrue(TicketEvent::query()->where(['ticket_id' => $ticket->id, 'type' => 'note_deleted', 'actor_user_id' => $autor->id])->exists());
    }
}
