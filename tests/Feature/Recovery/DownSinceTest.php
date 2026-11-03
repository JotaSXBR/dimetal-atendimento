<?php

namespace Tests\Feature\Recovery;

use App\Enums\ChannelStatus;
use App\Jobs\RecoverMessagesJob;
use App\Models\Channel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DownSinceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->startOfSecond());
        Queue::fake();
    }

    public function test_sair_de_working_grava_down_since(): void
    {
        $channel = Channel::factory()->connected()->create();

        $channel->update(['status' => ChannelStatus::STARTING]);

        $this->assertEquals(now(), $channel->fresh()->down_since);
    }

    public function test_queda_menor_que_5_min_nao_agenda_e_limpa_down_since(): void
    {
        $channel = Channel::factory()->connected()->create();
        $channel->update(['status' => ChannelStatus::STARTING]);
        $this->travel(299)->seconds();

        $channel->update(['status' => ChannelStatus::WORKING]);

        Queue::assertNotPushed(RecoverMessagesJob::class);
        $this->assertNull($channel->fresh()->down_since);
    }

    public function test_queda_de_5_min_agenda_o_job_com_janela_e_atraso(): void
    {
        $channel = Channel::factory()->connected()->create();
        $since = now()->toImmutable();
        $channel->update(['status' => ChannelStatus::STARTING]);
        $this->travel(5)->minutes();

        $channel->update(['status' => ChannelStatus::WORKING]);

        Queue::assertPushed(RecoverMessagesJob::class, fn (RecoverMessagesJob $job): bool => $job->channelId === $channel->id
            && $job->from->equalTo($since->subMinutes(10))
            && $job->to->equalTo(now())
            && $job->downSince?->equalTo($since) === true
            && $job->delay === 60
            && ! $job->notifyAlways);
        $this->assertNull($channel->fresh()->down_since);
    }

    public function test_oscilacao_preserva_o_primeiro_down_since(): void
    {
        $channel = Channel::factory()->connected()->create();
        $channel->update(['status' => ChannelStatus::STARTING]);
        $first = $channel->fresh()->down_since;
        $this->travel(2)->minutes();

        $channel->update(['status' => ChannelStatus::SCAN_QR_CODE]);

        $this->assertEquals($first, $channel->fresh()->down_since);
    }

    public function test_canal_desativado_nao_marca_queda_nem_agenda(): void
    {
        $channel = Channel::factory()->connected()->create();

        $channel->update(['active' => false, 'status' => ChannelStatus::STOPPED]);
        $this->assertNull($channel->fresh()->down_since);

        $this->travel(10)->minutes();
        $channel->update(['status' => ChannelStatus::WORKING]);

        Queue::assertNotPushed(RecoverMessagesJob::class);
    }

    public function test_canal_desativado_na_volta_nao_agenda(): void
    {
        $channel = Channel::factory()->connected()->create();
        $channel->update(['status' => ChannelStatus::STARTING]);
        $this->travel(10)->minutes();

        $channel->update(['active' => false, 'status' => ChannelStatus::WORKING]);

        Queue::assertNotPushed(RecoverMessagesJob::class);
        $this->assertNull($channel->fresh()->down_since);
    }
}
