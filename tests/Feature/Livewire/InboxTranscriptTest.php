<?php

namespace Tests\Feature\Livewire;

use App\Enums\DeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Inbox;
use App\Livewire\Inbox\Transcript;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Inbox\SendFailure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Attributes\On;
use Livewire\Livewire;
use ReflectionAttribute;
use ReflectionMethod;
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

    /** @return array{0: User, 1: Ticket} um admin e um ticket em atendimento com ele */
    private function mine(): array
    {
        $me = User::factory()->create(['role' => UserRole::ADMIN, 'sign_messages' => false]);
        $ticket = $this->ticket();
        $ticket->update(['status' => TicketStatus::EM_ATENDIMENTO, 'assigned_user_id' => $me->id]);

        return [$me, $ticket];
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

    public function test_digitar_barra_nao_consulta_o_transcript(): void
    {
        [$me, $ticket] = $this->mine();
        $screen = Livewire::actingAs($me)->test(Inbox::class)->call('select', $ticket->id);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $screen->set('draft', '/');

        $transcriptQueries = array_filter(DB::getQueryLog(), fn (array $q): bool => str_contains($q['query'], 'limit 200'));
        $this->assertSame([], $transcriptQueries);
    }

    public function test_inbox_nao_escuta_message_status(): void
    {
        $events = array_map(
            fn (ReflectionAttribute $a): string => $a->getArguments()[0],
            (new ReflectionMethod(Inbox::class, 'refresh'))->getAttributes(On::class),
        );

        $this->assertNotContains('echo-private:inbox,.message.status', $events);
        $this->assertContains('echo-private:inbox,.ticket.updated', $events);
    }

    public function test_envio_pelo_composer_avisa_o_transcript(): void
    {
        Queue::fake();
        [$me, $ticket] = $this->mine();

        Livewire::actingAs($me)->test(Inbox::class)->call('select', $ticket->id)
            ->set('draft', 'oi do atendente')->call('send')
            ->assertDispatched('transcript-refresh');
    }

    public function test_reenviar_avisa_o_transcript(): void
    {
        Queue::fake();
        [$me, $ticket] = $this->mine();
        $failed = Message::factory()->for($ticket)->create([
            'direction' => MessageDirection::OUT, 'origin' => MessageOrigin::PAINEL, 'author_user_id' => $me->id,
            'delivery_status' => DeliveryStatus::FAILED, 'failure_code' => SendFailure::EXPIRED, 'wa_message_id' => null,
        ]);

        Livewire::actingAs($me)->test(Inbox::class)->call('select', $ticket->id)
            ->call('resend', $failed->id, true)
            ->assertDispatched('transcript-refresh');
    }
}
