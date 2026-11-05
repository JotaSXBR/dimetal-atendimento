<?php

namespace Tests\Feature\Livewire;

use App\Enums\ChannelStatus;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Alerts\Bell;
use App\Livewire\Contacts\ContactCard;
use App\Livewire\Inbox\Inbox;
use App\Livewire\Inbox\Transcript;
use App\Livewire\Settings\ChannelCard;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\ChannelUp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * O Reverb não reenvia o que se perdeu: depois de uma queda do websocket (notebook que dormiu, rede, deploy) as telas
 * pedem nova leitura do banco (`realtime-reconnected`), e o estado do canal chega por evento, não só pelo polling.
 */
class RealtimeRefreshTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);
    }

    private function ticket(): Ticket
    {
        return Ticket::factory()->create([
            'channel_id' => Channel::factory()->connected()->create()->id,
            'contact_id' => Contact::factory()->create(['name' => 'Cliente Realtime'])->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            'assigned_user_id' => $this->admin->id,
        ]);
    }

    public function test_cartao_de_canal_acompanha_o_evento_de_estado_sem_esperar_o_polling(): void
    {
        $channel = Channel::factory()->create(['status' => ChannelStatus::STOPPED]);

        $screen = Livewire::actingAs($this->admin)->test(ChannelCard::class, ['channel' => $channel])
            ->assertSee(ChannelStatus::STOPPED->label());

        $channel->update(['status' => ChannelStatus::WORKING]);

        $screen->dispatch('echo-private:inbox,.channel.status', ['channel_id' => $channel->id, 'status' => 'WORKING'])
            ->assertSee(ChannelStatus::WORKING->label());
    }

    public function test_cartao_de_canal_ignora_o_estado_de_outro_canal(): void
    {
        $mine = Channel::factory()->create(['status' => ChannelStatus::STOPPED, 'waha_session' => 'a']);
        $other = Channel::factory()->create(['status' => ChannelStatus::STOPPED, 'waha_session' => 'b']);

        $screen = Livewire::actingAs($this->admin)->test(ChannelCard::class, ['channel' => $mine]);

        $mine->update(['status' => ChannelStatus::WORKING]);

        $screen->dispatch('echo-private:inbox,.channel.status', ['channel_id' => $other->id, 'status' => 'WORKING'])
            ->assertSee(ChannelStatus::STOPPED->label())
            ->assertDontSee(ChannelStatus::WORKING->label());
    }

    public function test_conversa_se_atualiza_quando_o_websocket_volta(): void
    {
        $ticket = $this->ticket();
        $screen = Livewire::actingAs($this->admin)->test(Transcript::class, ['ticketId' => $ticket->id])->assertDontSee('mensagem que chegou na queda');

        Message::factory()->for($ticket)->create(['body' => 'mensagem que chegou na queda']);

        $screen->dispatch('realtime-reconnected')->assertSee('mensagem que chegou na queda');
    }

    public function test_fila_se_atualiza_quando_o_websocket_volta(): void
    {
        $screen = Livewire::actingAs($this->admin)->test(Inbox::class)->assertDontSee('Cliente Realtime');

        $this->ticket();

        $screen->dispatch('realtime-reconnected')->assertSee('Cliente Realtime');
    }

    public function test_fila_acompanha_o_estado_do_canal_do_ticket_aberto(): void
    {
        $ticket = $this->ticket();
        $ticket->channel->update(['name' => 'Canal Antigo']);

        $screen = Livewire::actingAs($this->admin)->test(Inbox::class)->call('select', $ticket->id)->assertSee('Canal Antigo');

        $ticket->channel->update(['name' => 'Canal Renomeado', 'status' => ChannelStatus::STOPPED]);

        $screen->dispatch('echo-private:inbox,.channel.status', ['channel_id' => $ticket->channel_id, 'status' => 'STOPPED'])
            ->assertSee('Canal Renomeado');
    }

    public function test_ficha_de_contato_do_inbox_se_atualiza_quando_o_websocket_volta(): void
    {
        $ticket = $this->ticket();

        $screen = Livewire::actingAs($this->admin)->test(ContactCard::class, ['contactId' => $ticket->contact_id, 'compact' => true, 'currentTicketId' => $ticket->id]);

        $earlier = Ticket::factory()->create(['channel_id' => $ticket->channel_id, 'contact_id' => $ticket->contact_id, 'status' => TicketStatus::ENCERRADO, 'protocol' => '2026-000777']);

        $screen->dispatch('realtime-reconnected')->assertSee($earlier->protocol);
    }

    public function test_sino_mostra_o_horario_para_o_navegador_atualizar_sozinho(): void
    {
        $this->admin->notify(new ChannelUp(Channel::factory()->create(['name' => 'Canal do sino'])));

        $html = Livewire::actingAs($this->admin)->test(Bell::class)->assertSee('Canal do sino')->html();

        // O texto "há 3 segundos" é calculado no servidor e congela; o navegador recalcula a partir do instante gravado.
        $this->assertMatchesRegularExpression('/<time[^>]*x-data="relativeTime\(/', $html);
        $this->assertMatchesRegularExpression('/datetime="\d{4}-\d{2}-\d{2}T/', $html);
    }
}
