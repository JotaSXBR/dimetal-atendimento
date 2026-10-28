<?php

namespace Tests\Feature\Inbox;

use App\Enums\ClosedReason;
use App\Enums\TicketStatus;
use App\Models\Channel;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CloseInactiveTicketsTest extends TestCase
{
    use RefreshDatabase;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = Channel::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function ticket(array $attributes): Ticket
    {
        return Ticket::factory()->create($attributes + [
            'channel_id' => $this->channel->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            'assigned_user_id' => User::factory(),
            'opened_at' => now()->subDays(5),
        ]);
    }

    public function test_fecha_o_ticket_parado_ha_mais_de_24h_cuja_ultima_mensagem_e_nossa(): void
    {
        $ticket = $this->ticket([
            'last_inbound_at' => now()->subHours(30),
            'last_outbound_at' => now()->subHours(25),
        ]);

        $this->artisan('app:close-inactive-tickets')->expectsOutput('Tickets encerrados por inatividade: 1')->assertSuccessful();

        $ticket->refresh();
        $this->assertSame(TicketStatus::ENCERRADO, $ticket->status);
        $this->assertSame(ClosedReason::INATIVIDADE, $ticket->closed_reason);
        $this->assertNotNull($ticket->closed_at);

        $event = $ticket->events()->where('type', 'closed')->sole();
        $this->assertNull($event->actor_user_id);
        $this->assertSame(['reason' => 'inatividade'], $event->payload);
    }

    public function test_nunca_fecha_quando_a_ultima_mensagem_e_do_cliente_por_mais_velha_que_seja(): void
    {
        $ticket = $this->ticket([
            'last_outbound_at' => now()->subDays(4),
            'last_inbound_at' => now()->subDays(3),
        ]);

        $this->artisan('app:close-inactive-tickets')->assertSuccessful();

        $this->assertSame(TicketStatus::EM_ATENDIMENTO, $ticket->fresh()?->status);
    }

    public function test_ticket_aguardando_so_com_mensagem_do_cliente_nao_fecha(): void
    {
        $ticket = $this->ticket([
            'status' => TicketStatus::AGUARDANDO,
            'assigned_user_id' => null,
            'last_inbound_at' => now()->subDays(10),
        ]);

        $this->artisan('app:close-inactive-tickets')->assertSuccessful();

        $this->assertSame(TicketStatus::AGUARDANDO, $ticket->fresh()?->status);
    }

    public function test_nao_fecha_antes_de_24h(): void
    {
        $ticket = $this->ticket([
            'last_inbound_at' => now()->subHours(30),
            'last_outbound_at' => now()->subHours(23),
        ]);

        $this->artisan('app:close-inactive-tickets')->assertSuccessful();

        $this->assertSame(TicketStatus::EM_ATENDIMENTO, $ticket->fresh()?->status);
    }

    public function test_ticket_aberto_pelo_celular_sem_mensagem_do_cliente_tambem_fecha(): void
    {
        $ticket = $this->ticket(['last_inbound_at' => null, 'last_outbound_at' => now()->subHours(26)]);

        $this->artisan('app:close-inactive-tickets')->assertSuccessful();

        $this->assertSame(TicketStatus::ENCERRADO, $ticket->fresh()?->status);
    }

    public function test_rodar_duas_vezes_nao_duplica_evento_nem_reabre(): void
    {
        $ticket = $this->ticket(['last_outbound_at' => now()->subHours(40)]);

        $this->artisan('app:close-inactive-tickets')->assertSuccessful();
        $this->artisan('app:close-inactive-tickets')->expectsOutput('Tickets encerrados por inatividade: 0')->assertSuccessful();

        $this->assertSame(1, $ticket->events()->where('type', 'closed')->count());
        $this->assertSame(TicketStatus::ENCERRADO, $ticket->fresh()?->status);
    }

    public function test_esta_agendado_a_cada_dez_minutos(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains($e->command, 'app:close-inactive-tickets'));

        $this->assertNotNull($event);
        $this->assertSame('*/10 * * * *', $event->expression);
    }
}
