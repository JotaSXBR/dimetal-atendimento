<?php

namespace Tests\Feature\Recovery;

use App\Enums\ChannelStatus;
use App\Jobs\ImportHistoryJob;
use App\Models\Channel;
use App\Models\User;
use App\Notifications\MessagesRecovered;
use App\Services\Inbox\ImportHistory;
use App\Services\Inbox\RecoveryResult;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ImportHistoryJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
    }

    public function test_primeira_vez_em_working_com_backfill_dispara_uma_vez(): void
    {
        Queue::fake();
        $channel = Channel::factory()->create(['status' => ChannelStatus::STARTING, 'backfill_days' => 5]);

        $channel->update(['status' => ChannelStatus::WORKING]);
        $channel->update(['status' => ChannelStatus::STARTING]);
        $channel->update(['status' => ChannelStatus::WORKING]);

        Queue::assertPushed(ImportHistoryJob::class, 1);
        Queue::assertPushed(ImportHistoryJob::class, fn (ImportHistoryJob $job): bool => $job->channelId === $channel->id && $job->delay === 60);
        $this->assertNotNull($channel->fresh()->backfill_last_run_at);
    }

    public function test_sem_backfill_days_nao_dispara(): void
    {
        Queue::fake();
        $channel = Channel::factory()->create(['status' => ChannelStatus::STARTING, 'backfill_days' => 0]);

        $channel->update(['status' => ChannelStatus::WORKING]);

        Queue::assertNotPushed(ImportHistoryJob::class);
    }

    public function test_canal_inativo_nao_dispara(): void
    {
        Queue::fake();
        $channel = Channel::factory()->create(['status' => ChannelStatus::STARTING, 'backfill_days' => 5, 'active' => false]);

        $channel->update(['status' => ChannelStatus::WORKING]);

        Queue::assertNotPushed(ImportHistoryJob::class);
        $this->assertNull($channel->fresh()->backfill_last_run_at);
    }

    public function test_job_que_falha_libera_nova_tentativa(): void
    {
        $channel = Channel::factory()->connected()->create(['backfill_last_run_at' => now()]);

        (new ImportHistoryJob($channel->id))->failed(new RuntimeException('x'));

        $this->assertNull($channel->fresh()->backfill_last_run_at);
    }

    public function test_canal_inativo_ou_fora_de_working_nao_importa(): void
    {
        Notification::fake();
        $this->mock(ImportHistory::class, fn ($m) => $m->shouldNotReceive('__invoke'));

        app()->call([new ImportHistoryJob(Channel::factory()->connected()->create(['active' => false])->id), 'handle']);
        app()->call([new ImportHistoryJob(Channel::factory()->create(['status' => ChannelStatus::STARTING, 'waha_session' => 'outra'])->id), 'handle']);

        Notification::assertNothingSent();
    }

    public function test_avisa_com_o_resumo_da_importacao(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $channel = Channel::factory()->connected()->create();
        $service = Mockery::mock(ImportHistory::class);
        $service->shouldReceive('__invoke')->once()->andReturn(new RecoveryResult(12, 4));
        $service->open = 1;
        $this->instance(ImportHistory::class, $service);

        app()->call([new ImportHistoryJob($channel->id), 'handle']);

        Notification::assertSentTo($user, MessagesRecovered::class, fn (MessagesRecovered $n): bool => $n->toArray($user) === [
            'kind' => 'recovery', 'level' => 'info', 'state' => 'imported',
            'channel_id' => $channel->id, 'channel_name' => $channel->name, 'messages' => 12, 'conversations' => 4, 'open' => 1,
        ]);
    }

    public function test_e_unico_por_canal_e_vai_para_a_fila_history(): void
    {
        $job = new ImportHistoryJob(7);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('7', $job->uniqueId());
        $this->assertSame('history', $job->queue);
    }

    public function test_o_lock_de_unicidade_expira_para_nao_travar_o_canal_se_o_worker_morrer(): void
    {
        $job = new ImportHistoryJob(7);

        $this->assertGreaterThan(0, $job->uniqueFor);
        $this->assertLessThanOrEqual(1800, $job->uniqueFor);
    }
}
