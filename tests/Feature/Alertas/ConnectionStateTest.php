<?php

namespace Tests\Feature\Alertas;

use App\Enums\ChannelStatus;
use App\Models\Channel;
use App\Support\ConnectionState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConnectionStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_lista_so_canais_ativos_fora_do_ar_e_some_ao_voltar(): void
    {
        $caido = Channel::factory()->create(['waha_session' => 'a', 'status' => ChannelStatus::SCAN_QR_CODE]);
        Channel::factory()->create(['waha_session' => 'b', 'status' => ChannelStatus::WORKING]);
        Channel::factory()->create(['waha_session' => 'c', 'status' => ChannelStatus::FAILED, 'active' => false]);

        $this->assertSame([$caido->id], ConnectionState::down()->pluck('id')->all());

        $caido->update(['status' => ChannelStatus::WORKING]);

        $this->assertCount(0, ConnectionState::down());
    }
}
