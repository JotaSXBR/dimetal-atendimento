<?php

namespace Tests\Feature\Bot;

use App\Enums\TicketStatus;
use App\Models\BotSession;
use App\Models\Channel;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\WorkflowVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ExpireSessionsTest extends TestCase
{
    use RefreshDatabase;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->channel = Channel::factory()->create();
        Sector::factory()->create(['name' => 'Vendas', 'menu_order' => 1]);
    }

    private function botSession(string $expires): BotSession
    {
        $ticket = Ticket::factory()->create(['status' => TicketStatus::PENDENTE, 'channel_id' => $this->channel->id]);

        return BotSession::factory()->create([
            'ticket_id' => $ticket->id,
            'workflow_version_id' => WorkflowVersion::factory()->create()->id,
            'waiting' => 'menu',
            'expires_at' => now()->modify($expires),
        ]);
    }

    public function test_sessao_vencida_vai_a_fila_padrao_por_timeout_e_so_uma_vez(): void
    {
        $expired = $this->botSession('-1 minute');
        $fresh = $this->botSession('+5 minutes');

        $this->artisan('app:expire-bot-sessions')->assertSuccessful();
        $this->artisan('app:expire-bot-sessions')->assertSuccessful();

        $this->assertSame(BotSession::TRANSFERRED, $expired->fresh()?->state);
        $this->assertSame(TicketStatus::AGUARDANDO, $expired->ticket()->first()?->status);
        $this->assertSame(1, TicketEvent::query()->where('ticket_id', $expired->ticket_id)->where('type', 'bot_handoff')->count());
        $this->assertSame('timeout', TicketEvent::query()->where('ticket_id', $expired->ticket_id)->where('type', 'bot_handoff')->firstOrFail()->payload['reason']);
        $this->assertSame(BotSession::ACTIVE, $fresh->fresh()?->state);
        $this->assertSame(TicketStatus::PENDENTE, $fresh->ticket()->first()?->status);
    }
}
