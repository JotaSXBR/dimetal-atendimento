<?php

namespace Tests\Feature\Inbox;

use App\Enums\DeliveryStatus;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutboxStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_aguardando_conexao_e_um_estado_de_entrega_abaixo_de_enviada(): void
    {
        $this->assertSame('queued', DeliveryStatus::QUEUED->value);
        $this->assertLessThan(DeliveryStatus::SENT->rank(), DeliveryStatus::QUEUED->rank());
    }

    public function test_a_mensagem_guarda_o_codigo_da_falha(): void
    {
        $id = Message::factory()->create(['delivery_status' => DeliveryStatus::FAILED, 'failure_code' => 'unknown'])->id;

        $this->assertSame('unknown', Message::findOrFail($id)->failure_code);
        $this->assertSame(DeliveryStatus::FAILED, Message::findOrFail($id)->delivery_status);
    }
}
