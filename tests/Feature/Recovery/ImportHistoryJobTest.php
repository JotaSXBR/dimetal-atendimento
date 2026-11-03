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
use Illuminate\Support\Facades\Cache;
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

    public function test_canal_inativo_ou_apagado_nao_importa_nem_reenfileira(): void
    {
        Notification::fake();
        $this->mock(ImportHistory::class, fn ($m) => $m->shouldNotReceive('__invoke'));
        $inativo = $this->trackedJob(Channel::factory()->connected()->create(['active' => false])->id);
        $apagado = $this->trackedJob(999999);

        app()->call([$inativo, 'handle']);
        app()->call([$apagado, 'handle']);

        $this->assertNull($inativo->released);
        $this->assertNull($apagado->released);
        Notification::assertNothingSent();
    }

    public function test_canal_ativo_fora_de_working_reenfileira_em_vez_de_perder_a_importacao(): void
    {
        Notification::fake();
        $this->mock(ImportHistory::class, fn ($m) => $m->shouldNotReceive('__invoke'));
        $job = $this->trackedJob(Channel::factory()->create(['status' => ChannelStatus::STARTING, 'waha_session' => 'outra'])->id);

        app()->call([$job, 'handle']);

        $this->assertSame(60, $job->released);
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
        $service->complete = true;
        $this->instance(ImportHistory::class, $service);

        app()->call([new ImportHistoryJob($channel->id), 'handle']);

        Notification::assertSentTo($user, MessagesRecovered::class, fn (MessagesRecovered $n): bool => $n->toArray($user) === [
            'kind' => 'recovery', 'level' => 'info', 'state' => 'imported',
            'channel_id' => $channel->id, 'channel_name' => $channel->name, 'messages' => 12, 'conversations' => 4, 'open' => 1,
        ]);
    }

    /** @return ImportHistoryJob um job cujo `release` só anota o atraso */
    private function trackedJob(int $channelId): ImportHistoryJob
    {
        return new class($channelId) extends ImportHistoryJob
        {
            public ?int $released = null;

            public function release($delay = 0): void
            {
                $this->released = (int) $delay;
            }
        };
    }

    /** Os totais ficam no cache entre as rodadas; com cache compartilhado (Redis) não podem vazar para outro teste. */
    private function forgetTotals(int $channelId): void
    {
        foreach (['messages', 'conversations', 'open'] as $name) {
            Cache::forget('import-totals:'.$channelId.':'.$name);
        }
    }

    private function serviceReturns(RecoveryResult $result, int $open, bool $complete, bool $truncated = false): void
    {
        $service = Mockery::mock(ImportHistory::class);
        $service->shouldReceive('__invoke')->once()->andReturn($result);
        $service->open = $open;
        $service->complete = $complete;
        $service->truncated = $truncated;
        $this->instance(ImportHistory::class, $service);
    }

    public function test_incompleta_reenfileira_sem_avisar(): void
    {
        Notification::fake();
        User::factory()->create();
        $channel = Channel::factory()->connected()->create();
        $this->forgetTotals($channel->id);
        $this->serviceReturns(new RecoveryResult(3, 1), 0, false);
        $job = $this->trackedJob($channel->id);

        app()->call([$job, 'handle']);

        $this->forgetTotals($channel->id);
        $this->assertSame(30, $job->released);
        Notification::assertNothingSent();
    }

    public function test_ultima_rodada_avisa_com_os_totais_acumulados(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $channel = Channel::factory()->connected()->create();
        $this->forgetTotals($channel->id);

        $this->serviceReturns(new RecoveryResult(3, 1), 0, false);
        app()->call([$this->trackedJob($channel->id), 'handle']);
        $this->serviceReturns(new RecoveryResult(2, 1), 1, true);
        $last = $this->trackedJob($channel->id);
        app()->call([$last, 'handle']);

        $this->assertNull($last->released);
        Notification::assertSentTo($user, MessagesRecovered::class, fn (MessagesRecovered $n): bool => $n->toArray($user)['messages'] === 5
            && $n->toArray($user)['conversations'] === 2 && $n->toArray($user)['open'] === 1);
    }

    public function test_repassa_o_limite_ao_aviso(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $channel = Channel::factory()->connected()->create();
        $this->forgetTotals($channel->id);
        $this->serviceReturns(new RecoveryResult(50000, 900), 3, true, true);

        app()->call([$this->trackedJob($channel->id), 'handle']);

        Notification::assertSentTo($user, MessagesRecovered::class, fn (MessagesRecovered $n): bool => ($n->toArray($user)['limited'] ?? 0) === 1);
    }

    public function test_erro_do_waha_nao_vira_repeticao_imediata(): void
    {
        $job = new ImportHistoryJob(7);

        $this->assertSame(60, $job->backoff);
        $this->assertSame(3, $job->maxExceptions);
    }

    public function test_pode_continuar_por_horas_sem_estourar_as_tentativas(): void
    {
        $until = (new ImportHistoryJob(7))->retryUntil();

        $this->assertGreaterThan(now()->addHours(2)->getTimestamp(), $until->getTimestamp());
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
