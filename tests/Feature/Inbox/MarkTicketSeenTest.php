<?php

namespace Tests\Feature\Inbox;

use App\Enums\MessageDirection;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Jobs\MarkTicketSeenJob;
use App\Livewire\Inbox\Inbox;
use App\Livewire\Inbox\Transcript;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Inbox\Presence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class MarkTicketSeenTest extends TestCase
{
    use RefreshDatabase;

    private const PEER = '5516997524950@c.us';

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
        Http::preventStrayRequests();

        $this->ticket = Ticket::factory()
            ->for(Contact::factory()->create(['wa_id' => self::PEER]))
            ->for(Channel::factory()->connected()->create(['waha_session' => 'main']))
            ->create(['status' => TicketStatus::EM_ATENDIMENTO]);
    }

    private function received(string $waId): Message
    {
        return Message::factory()->for($this->ticket)->create(['direction' => MessageDirection::IN, 'wa_message_id' => $waId]);
    }

    public function test_job_envia_visto_com_o_id_completo_das_mensagens_do_cliente(): void
    {
        Http::fake();
        $this->received('AAA');

        (new MarkTicketSeenJob($this->ticket->id))->handle(app(Presence::class));

        Http::assertSent(fn (Request $r): bool => str_ends_with($r->url(), '/api/sendSeen')
            && $r['chatId'] === self::PEER
            && $r['messageIds'] === ['false_'.self::PEER.'_AAA']);
    }

    public function test_ticket_em_triagem_do_bot_nao_recebe_visto_de_quem_so_esta_lendo(): void
    {
        Http::fake();
        $this->ticket->update(['status' => TicketStatus::PENDENTE]);
        $this->received('AAA');

        (new MarkTicketSeenJob($this->ticket->id))->handle(app(Presence::class));

        Http::assertNothingSent();
    }

    public function test_job_nao_chama_o_waha_sem_mensagem_do_cliente(): void
    {
        Http::fake();

        (new MarkTicketSeenJob($this->ticket->id))->handle(app(Presence::class));

        Http::assertNothingSent();
    }

    public function test_abrir_o_ticket_agenda_o_visto(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);

        Livewire::actingAs($admin)->test(Inbox::class)->call('select', $this->ticket->id);

        Queue::assertPushed(MarkTicketSeenJob::class, fn (MarkTicketSeenJob $j): bool => $j->ticketId === $this->ticket->id);
    }

    public function test_abrir_ticket_encerrado_nao_agenda_o_visto(): void
    {
        Queue::fake();
        $this->ticket->update(['status' => TicketStatus::ENCERRADO]);
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);

        Livewire::actingAs($admin)->test(Inbox::class)->call('select', $this->ticket->id);

        Queue::assertNotPushed(MarkTicketSeenJob::class);
    }

    public function test_mensagem_nova_do_cliente_no_ticket_aberto_agenda_o_visto(): void
    {
        Queue::fake();
        $screen = Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))
            ->test(Transcript::class, ['ticketId' => $this->ticket->id]);

        $screen->dispatch('echo-private:inbox,.message.created', ['ticket_id' => $this->ticket->id, 'direction' => 'out']);
        $screen->dispatch('echo-private:inbox,.message.created', ['ticket_id' => $this->ticket->id + 1, 'direction' => 'in']);
        Queue::assertNotPushed(MarkTicketSeenJob::class);

        $screen->dispatch('echo-private:inbox,.message.created', ['ticket_id' => $this->ticket->id, 'direction' => 'in']);
        Queue::assertPushed(MarkTicketSeenJob::class, 1);
    }
}
