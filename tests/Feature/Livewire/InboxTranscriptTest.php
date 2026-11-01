<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Transcript;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InboxTranscriptTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(?Sector $sector = null): Ticket
    {
        return Ticket::factory()->create([
            'channel_id' => Channel::query()->first()?->id ?? Channel::factory()->create()->id,
            'contact_id' => Contact::factory()->create()->id,
            'status' => TicketStatus::AGUARDANDO,
            'sector_id' => $sector?->id,
        ]);
    }

    private function say(Ticket $ticket, string $body): Message
    {
        return Message::factory()->for($ticket)->create(['body' => $body]);
    }

    public function test_mostra_as_200_ultimas_mensagens_em_ordem(): void
    {
        $ticket = $this->ticket();

        foreach (range(1, 205) as $n) {
            $this->say($ticket, "msg-{$n};");
        }

        Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))
            ->test(Transcript::class, ['ticketId' => $ticket->id])
            ->assertSeeInOrder(['msg-6;', 'msg-100;', 'msg-205;'])
            ->assertDontSee('msg-5;');
    }

    public function test_usuario_sem_acesso_ao_ticket_nao_ve_mensagens(): void
    {
        $ticket = $this->ticket(Sector::factory()->create());
        $this->say($ticket, 'segredo;');

        Livewire::actingAs(User::factory()->create(['role' => UserRole::ATENDENTE]))
            ->test(Transcript::class, ['ticketId' => $ticket->id])
            ->assertDontSee('segredo;');
    }

    public function test_evento_de_outro_ticket_nao_atualiza(): void
    {
        $ticket = $this->ticket();
        $other = $this->ticket();
        $screen = Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))
            ->test(Transcript::class, ['ticketId' => $ticket->id]);

        $this->say($ticket, 'nova;');

        $screen->dispatch('echo-private:inbox,.message.created', ['ticket_id' => $other->id])->assertDontSee('nova;');
        $screen->dispatch('echo-private:inbox,.message.created', ['ticket_id' => $ticket->id])->assertSee('nova;');
    }

    public function test_transcript_refresh_atualiza(): void
    {
        $ticket = $this->ticket();
        $screen = Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))
            ->test(Transcript::class, ['ticketId' => $ticket->id]);

        $this->say($ticket, 'nova;');

        $screen->dispatch('transcript-refresh')->assertSee('nova;');
    }
}
