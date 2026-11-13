<?php

namespace Tests\Feature\Models;

use App\Enums\TicketStatus;
use App\Models\Channel;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AgentSeenBackfillTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_11_13_000003_add_agent_seen_at_to_tickets.php';

    /** No deploy, os tickets que já existem não podem nascer com "todas as mensagens não lidas". */
    public function test_a_migracao_marca_os_tickets_abertos_como_vistos_e_deixa_os_encerrados(): void
    {
        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]);
        $channel = Channel::factory()->create();
        $open = Ticket::factory()->create(['channel_id' => $channel->id, 'status' => TicketStatus::AGUARDANDO]);
        $closed = Ticket::factory()->create(['channel_id' => $channel->id, 'status' => TicketStatus::ENCERRADO, 'closed_at' => now()]);

        Artisan::call('migrate', ['--path' => self::MIGRATION]);

        $this->assertNotNull($open->fresh()?->agent_seen_at);
        $this->assertNull($closed->fresh()?->agent_seen_at);
    }
}
