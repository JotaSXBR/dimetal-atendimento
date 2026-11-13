<?php

namespace Tests\Feature\Models;

use App\Enums\MessageDirection;
use App\Enums\TicketStatus;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AgentSeenWatermarkMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_11_13_000005_add_agent_seen_message_id_to_tickets.php';

    private function inbound(Ticket $ticket, string $at): Message
    {
        return Message::factory()->for($ticket)->create(['direction' => MessageDirection::IN, 'sent_at' => $at]);
    }

    /** Quem já tinha `agent_seen_at` vira marca d'água na última mensagem do cliente até aquele instante; sem visto, fica nula (tudo não lido). */
    public function test_a_migracao_converte_o_visto_por_horario_em_marca_dagua_por_id(): void
    {
        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION]);
        $channel = Channel::factory()->create();
        $seen = now()->startOfSecond();
        $viewed = Ticket::factory()->create(['channel_id' => $channel->id, 'status' => TicketStatus::EM_ATENDIMENTO, 'agent_seen_at' => $seen]);
        $this->inbound($viewed, $seen->copy()->subMinutes(2)->toDateTimeString());
        $lastSeen = $this->inbound($viewed, $seen->copy()->subMinute()->toDateTimeString());
        $this->inbound($viewed, $seen->copy()->addMinute()->toDateTimeString());
        $never = Ticket::factory()->create(['channel_id' => $channel->id, 'status' => TicketStatus::AGUARDANDO]);
        $this->inbound($never, $seen->copy()->subMinute()->toDateTimeString());

        Artisan::call('migrate', ['--path' => self::MIGRATION]);

        $this->assertSame($lastSeen->id, $viewed->fresh()?->agent_seen_message_id);
        $this->assertNull($never->fresh()?->agent_seen_message_id);
    }
}
