<?php

namespace Tests\Feature\Sla;

use App\Livewire\Audit\Index as AuditIndex;
use App\Models\TicketEvent;
use App\Support\TicketEventText;
use Tests\TestCase;

class SlaEventTextTest extends TestCase
{
    public function test_rotulo_do_sla_estourado(): void
    {
        $this->assertSame('SLA estourado', TicketEventText::label('sla_breached'));
        $this->assertSame('SLA estourado', AuditIndex::typeLabel('sla_breached'));
        $this->assertArrayHasKey('sla_breached', TicketEventText::TYPES);
    }

    public function test_detalhe_mostra_desde_quando_e_o_limite_no_fuso_de_brasilia(): void
    {
        $event = new TicketEvent(['type' => 'sla_breached', 'payload' => ['waiting_since' => '2026-10-30T13:00:00+00:00', 'due_at' => '2026-10-30T13:10:00+00:00']]);

        $this->assertSame('esperando desde 30/10 10:00, limite 30/10 10:10', TicketEventText::detail($event));
    }

    public function test_detalhe_sem_payload_valido_fica_vazio_em_vez_de_quebrar(): void
    {
        $this->assertSame('', TicketEventText::detail(new TicketEvent(['type' => 'sla_breached', 'payload' => null])));
        $this->assertSame('', TicketEventText::detail(new TicketEvent(['type' => 'sla_breached', 'payload' => ['waiting_since' => 'lixo', 'due_at' => 42]])));
    }
}
