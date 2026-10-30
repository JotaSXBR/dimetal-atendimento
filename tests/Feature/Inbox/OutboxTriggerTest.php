<?php

namespace Tests\Feature\Inbox;

use App\Enums\ChannelStatus;
use App\Jobs\DrainOutboxJob;
use App\Jobs\HandleWahaEvent;
use App\Models\Channel;
use App\Models\WahaEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OutboxTriggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_canal_que_passa_a_working_agenda_o_esvaziamento(): void
    {
        Queue::fake();
        $channel = Channel::factory()->create(['status' => ChannelStatus::STARTING]);

        $channel->update(['status' => ChannelStatus::WORKING]);

        Queue::assertPushed(DrainOutboxJob::class, fn (DrainOutboxJob $job) => $job->channelId === $channel->id);
    }

    public function test_webhook_de_status_agenda_o_esvaziamento_mesmo_se_a_tela_ja_gravou_working(): void
    {
        Queue::fake();
        $channel = Channel::factory()->create(['waha_session' => 'main', 'status' => ChannelStatus::STARTING]);
        $channel->update(['status' => ChannelStatus::WORKING]);
        Queue::assertPushed(DrainOutboxJob::class, 1);

        $event = WahaEvent::create([
            'waha_event_id' => 'evt_status',
            'channel_id' => $channel->id,
            'event' => 'session.status',
            'session' => 'main',
            'payload' => ['payload' => ['status' => 'WORKING']],
            'received_at' => now(),
        ]);
        (new HandleWahaEvent($event->id))->handle();

        // WORKING -> WORKING não é mudança: o job do primeiro gravar basta.
        Queue::assertPushed(DrainOutboxJob::class, 1);
    }

    public function test_working_para_working_e_outras_mudancas_nao_agendam(): void
    {
        Queue::fake();
        $channel = Channel::factory()->connected()->create();

        $channel->update(['name' => 'Novo nome']);
        $channel->update(['status' => ChannelStatus::STOPPED]);

        Queue::assertNotPushed(DrainOutboxJob::class);
    }

    public function test_dois_disparos_seguidos_deixam_um_unico_job_na_fila(): void
    {
        Queue::fake();

        DrainOutboxJob::dispatch(7);
        DrainOutboxJob::dispatch(7);

        Queue::assertPushed(DrainOutboxJob::class, 1);
    }
}
