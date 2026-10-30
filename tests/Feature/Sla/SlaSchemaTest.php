<?php

namespace Tests\Feature\Sla;

use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SlaSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_as_cinco_colunas_existem_e_sao_nulas(): void
    {
        $this->assertTrue(Schema::hasColumns('tickets', ['waiting_since', 'sla_warn_at', 'sla_due_at', 'sla_warned_at', 'sla_breached_at']));

        $ticket = Ticket::factory()->create()->fresh();

        foreach (['waiting_since', 'sla_warn_at', 'sla_due_at', 'sla_warned_at', 'sla_breached_at'] as $column) {
            $this->assertNull($ticket?->{$column}, $column);
        }
    }

    public function test_as_datas_do_sla_viram_carbon(): void
    {
        $ticket = Ticket::factory()->create(['waiting_since' => '2026-10-30 12:00:00', 'sla_due_at' => '2026-10-30 12:10:00'])->fresh();

        $this->assertSame('2026-10-30 12:10:00', $ticket?->sla_due_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-30 12:00:00', $ticket->waiting_since?->format('Y-m-d H:i:s'));
    }

    public function test_os_indices_parciais_existem(): void
    {
        foreach (['tickets_open_id', 'tickets_open_sla_due', 'tickets_closed_at'] as $name) {
            $index = DB::selectOne('select indexdef from pg_indexes where indexname = ?', [$name]);

            $this->assertNotNull($index, $name);
            $this->assertStringContainsString('WHERE', (string) $index->indexdef, $name);
        }
    }
}
