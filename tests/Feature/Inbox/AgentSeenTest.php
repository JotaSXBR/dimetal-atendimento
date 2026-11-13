<?php

namespace Tests\Feature\Inbox;

use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Events\TicketUpdated;
use App\Livewire\Inbox\Inbox;
use App\Livewire\Inbox\Transcript;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Inbox\SendTicketMessage;
use App\Support\TicketQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class AgentSeenTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->owner = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $this->ticket = Ticket::factory()
            ->for(Contact::factory()->create())
            ->for(Channel::factory()->connected()->create())
            ->create(['status' => TicketStatus::EM_ATENDIMENTO, 'assigned_user_id' => $this->owner->id]);
    }

    private function inbound(string $at = 'now'): Message
    {
        return Message::factory()->for($this->ticket)->create(['direction' => MessageDirection::IN, 'sent_at' => $at === 'now' ? now() : $at]);
    }

    private function unread(): int
    {
        return (int) Ticket::query()->tap(TicketQueue::withUnread(...))->findOrFail($this->ticket->id)->getAttribute('unread');
    }

    public function test_o_responsavel_abre_e_a_tela_de_todos_se_atualiza(): void
    {
        Event::fake([TicketUpdated::class]);
        $this->inbound();

        Livewire::actingAs($this->owner)->test(Inbox::class)->call('select', $this->ticket->id);

        $this->assertNotNull($this->ticket->fresh()?->agent_seen_at);
        Event::assertDispatched(TicketUpdated::class);
    }

    public function test_supervisor_que_so_espia_nao_zera_as_nao_lidas_do_atendente(): void
    {
        $this->inbound();

        Livewire::actingAs(User::factory()->create(['role' => UserRole::SUPERVISOR]))->test(Inbox::class)->call('select', $this->ticket->id);

        $this->assertNull($this->ticket->fresh()?->agent_seen_at);
        $this->assertSame(1, $this->unread());
    }

    public function test_ticket_sem_responsavel_e_zerado_por_quem_abre(): void
    {
        $this->ticket->update(['assigned_user_id' => null, 'status' => TicketStatus::AGUARDANDO]);
        $this->inbound();

        Livewire::actingAs($this->owner)->test(Inbox::class)->call('select', $this->ticket->id);

        $this->assertNotNull($this->ticket->fresh()?->agent_seen_at);
    }

    public function test_mensagem_nova_com_o_ticket_aberto_na_tela_do_responsavel_zera_de_novo(): void
    {
        $this->ticket->forceFill(['agent_seen_at' => now()->subMinutes(10)])->save();
        $this->inbound();
        $this->assertSame(1, $this->unread());

        Livewire::actingAs($this->owner)->test(Transcript::class, ['ticketId' => $this->ticket->id])
            ->dispatch('echo-private:inbox,.message.created', ['ticket_id' => $this->ticket->id, 'direction' => 'in']);

        $this->assertSame(0, $this->unread());
    }

    public function test_responder_pelo_painel_zera(): void
    {
        $this->inbound();

        app(SendTicketMessage::class)->create($this->ticket, $this->owner, 'Oi, já te atendo');

        $this->assertNotNull($this->ticket->fresh()?->agent_seen_at);
        $this->assertSame(0, $this->unread());
    }

    public function test_resposta_do_bot_ou_de_quem_nao_e_o_responsavel_nao_zera(): void
    {
        $this->inbound();

        app(SendTicketMessage::class)->create($this->ticket, null, 'Resposta automática', MessageOrigin::BOT);
        app(SendTicketMessage::class)->create($this->ticket, User::factory()->create(['role' => UserRole::SUPERVISOR]), 'Posso ajudar?');

        $this->assertNull($this->ticket->fresh()?->agent_seen_at);
    }

    public function test_abrir_sem_novidade_nao_dispara_atualizacao(): void
    {
        $this->inbound(now()->subMinutes(5)->toDateTimeString());
        $this->ticket->forceFill(['agent_seen_at' => now()->subMinute()])->save();
        Event::fake([TicketUpdated::class]);

        Livewire::actingAs($this->owner)->test(Inbox::class)->call('select', $this->ticket->id);

        Event::assertNotDispatched(TicketUpdated::class);
    }

    /** O visto grava até onde a pessoa viu (a última mensagem do cliente), não "agora": o que chega depois segue não lido. */
    public function test_visto_grava_a_ultima_mensagem_vista_e_a_que_chega_depois_continua_nao_lida(): void
    {
        $seen = $this->inbound(now()->subMinutes(5)->toDateTimeString());

        Livewire::actingAs($this->owner)->test(Inbox::class)->call('select', $this->ticket->id);

        $this->assertEquals($seen->sent_at, $this->ticket->fresh()?->agent_seen_at);

        $this->inbound(now()->subMinute()->toDateTimeString());
        $this->assertSame(1, $this->unread());
    }

    public function test_contador_conta_so_mensagens_do_cliente_depois_de_visto(): void
    {
        $this->assertSame(0, $this->unread(), 'ticket sem mensagens');

        $this->inbound(now()->subMinutes(30)->toDateTimeString());
        $this->inbound(now()->subMinutes(20)->toDateTimeString());
        $this->inbound(now()->subMinutes(2)->toDateTimeString());
        Message::factory()->for($this->ticket)->create(['direction' => MessageDirection::OUT, 'sent_at' => now()->subMinute()]);
        $this->assertSame(3, $this->unread(), 'sem agent_seen_at conta todas as do cliente');

        $this->ticket->forceFill(['agent_seen_at' => now()->subMinutes(10)])->save();
        $this->assertSame(1, $this->unread());
    }
}
