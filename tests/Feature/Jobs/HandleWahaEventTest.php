<?php

namespace Tests\Feature\Jobs;

use App\Enums\ChannelStatus;
use App\Jobs\HandleWahaEvent;
use App\Models\Channel;
use App\Models\Message;
use App\Models\WahaEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HandleWahaEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_da_sessao_atualiza_o_canal(): void
    {
        $channel = Channel::factory()->create([
            'waha_session' => 'main',
            'status' => ChannelStatus::STARTING,
        ]);
        $event = WahaEvent::create([
            'waha_event_id' => 'evt_01',
            'channel_id' => $channel->id,
            'event' => 'session.status',
            'session' => 'main',
            'payload' => ['payload' => ['status' => 'WORKING']],
            'received_at' => now(),
        ]);

        (new HandleWahaEvent($event->id))->handle();

        $this->assertSame(ChannelStatus::WORKING, $channel->fresh()->status);
        $this->assertNotNull($channel->fresh()->last_event_at);
    }

    public function test_evento_de_mensagem_vira_mensagem_no_inbox(): void
    {
        $channel = Channel::factory()->connected()->create(['waha_session' => 'main']);
        $event = WahaEvent::create([
            'waha_event_id' => 'evt_msg',
            'channel_id' => $channel->id,
            'event' => 'message',
            'session' => 'main',
            'payload' => ['payload' => [
                'id' => 'false_5516997524950@c.us_ABC',
                'from' => '5516997524950@c.us',
                'fromMe' => false,
                'body' => 'Oi',
                'timestamp' => 1_793_000_000,
            ]],
            'received_at' => now(),
        ]);

        (new HandleWahaEvent($event->id))->handle();

        $this->assertSame('Oi', Message::query()->sole()->body);
        $this->assertSame(ChannelStatus::WORKING, $channel->fresh()->status);
    }

    public function test_status_desconhecido_e_evento_sem_canal_sao_ignorados(): void
    {
        $channel = Channel::factory()->create([
            'waha_session' => 'main',
            'status' => ChannelStatus::WORKING,
        ]);
        $unknown = WahaEvent::create([
            'waha_event_id' => 'evt_02',
            'channel_id' => $channel->id,
            'event' => 'session.status',
            'session' => 'main',
            'payload' => ['payload' => ['status' => 'ALGO_NOVO']],
            'received_at' => now(),
        ]);
        $orphan = WahaEvent::create([
            'waha_event_id' => 'evt_03',
            'channel_id' => null,
            'event' => 'session.status',
            'session' => 'sumiu',
            'payload' => [],
            'received_at' => now(),
        ]);

        (new HandleWahaEvent($unknown->id))->handle();
        (new HandleWahaEvent($orphan->id))->handle();

        $this->assertSame(ChannelStatus::WORKING, $channel->fresh()->status);
    }
}
