<?php

namespace Tests\Feature\Recovery;

use App\Enums\ChannelStatus;
use App\Jobs\RecoverMessagesJob;
use App\Models\Channel;
use App\Models\User;
use App\Notifications\MessagesRecovered;
use App\Services\Inbox\RecoverMessages;
use App\Services\Inbox\RecoveryResult;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Mockery\MockInterface;
use Tests\TestCase;

class RecoverMessagesJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        Notification::fake();
    }

    private function runJob(Channel $channel, bool $notifyAlways = false, ?CarbonImmutable $downSince = null): void
    {
        $job = new RecoverMessagesJob($channel->id, CarbonImmutable::now()->subHours(2), CarbonImmutable::now(), $notifyAlways, $downSince);

        app()->call([$job, 'handle']);
    }

    private function serviceReturns(RecoveryResult $result): void
    {
        $this->mock(RecoverMessages::class, fn (MockInterface $m) => $m->shouldReceive('__invoke')->once()->andReturn($result));
    }

    private function serviceNeverRuns(): void
    {
        $this->mock(RecoverMessages::class, fn (MockInterface $m) => $m->shouldNotReceive('__invoke'));
    }

    public function test_numero_trocado_durante_a_queda_nao_recupera(): void
    {
        $since = CarbonImmutable::now()->subHour();
        $channel = Channel::factory()->connected()->create(['phone_changed_at' => $since->addMinutes(30)]);
        $this->serviceNeverRuns();

        $this->runJob($channel, false, $since);

        Notification::assertNothingSent();
    }

    /** @return RecoverMessagesJob um job cujo `release` só anota o atraso */
    private function trackedJob(int $channelId): RecoverMessagesJob
    {
        return new class($channelId, CarbonImmutable::now()->subHours(2), CarbonImmutable::now()) extends RecoverMessagesJob
        {
            public ?int $released = null;

            public function release($delay = 0): void
            {
                $this->released = (int) $delay;
            }
        };
    }

    public function test_canal_inativo_ou_apagado_sai_sem_reenfileirar(): void
    {
        $this->serviceNeverRuns();
        $inativo = $this->trackedJob(Channel::factory()->connected()->create(['active' => false])->id);
        $apagado = $this->trackedJob(999999);

        app()->call([$inativo, 'handle']);
        app()->call([$apagado, 'handle']);

        $this->assertNull($inativo->released);
        $this->assertNull($apagado->released);
        Notification::assertNothingSent();
    }

    public function test_canal_ativo_fora_de_working_reenfileira_em_vez_de_sumir(): void
    {
        $this->serviceNeverRuns();
        $job = $this->trackedJob(Channel::factory()->create(['status' => ChannelStatus::STARTING, 'waha_session' => 'outra'])->id);

        app()->call([$job, 'handle']);

        $this->assertSame(60, $job->released);
        Notification::assertNothingSent();
    }

    public function test_erro_do_waha_nao_vira_repeticao_imediata(): void
    {
        $job = new RecoverMessagesJob(7, CarbonImmutable::now(), CarbonImmutable::now());

        $this->assertSame(60, $job->backoff);
        $this->assertSame(3, $job->maxExceptions);
    }

    public function test_pode_reenfileirar_por_ate_1_hora(): void
    {
        $until = (new RecoverMessagesJob(7, CarbonImmutable::now(), CarbonImmutable::now()))->retryUntil();

        $this->assertGreaterThan(now()->addMinutes(50)->getTimestamp(), $until->getTimestamp());
        $this->assertLessThanOrEqual(now()->addMinutes(61)->getTimestamp(), $until->getTimestamp());
    }

    public function test_avisa_quando_recuperou_algo(): void
    {
        $user = User::factory()->create();
        $channel = Channel::factory()->connected()->create();
        $this->serviceReturns(new RecoveryResult(3, 2));

        $this->runJob($channel);

        Notification::assertSentTo($user, MessagesRecovered::class, fn (MessagesRecovered $n): bool => $n->toArray($user) === [
            'kind' => 'recovery', 'level' => 'info', 'state' => 'recovered',
            'channel_id' => $channel->id, 'channel_name' => $channel->name, 'messages' => 3, 'conversations' => 2,
        ]);
    }

    public function test_automatica_sem_nada_novo_nao_avisa(): void
    {
        User::factory()->create();
        $this->serviceReturns(new RecoveryResult(0, 0));

        $this->runJob(Channel::factory()->connected()->create());

        Notification::assertNothingSent();
    }

    public function test_manual_sem_nada_novo_avisa(): void
    {
        $user = User::factory()->create();
        $this->serviceReturns(new RecoveryResult(0, 0));

        $this->runJob(Channel::factory()->connected()->create(), true);

        Notification::assertSentTo($user, MessagesRecovered::class);
    }

    public function test_e_unico_por_canal_e_vai_para_a_fila_history(): void
    {
        $job = new RecoverMessagesJob(7, CarbonImmutable::now(), CarbonImmutable::now());

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('7', $job->uniqueId());
        $this->assertSame('history', $job->queue);
    }

    public function test_o_lock_de_unicidade_expira_para_nao_travar_o_canal_se_o_worker_morrer(): void
    {
        $job = new RecoverMessagesJob(7, CarbonImmutable::now(), CarbonImmutable::now());

        $this->assertGreaterThan(0, $job->uniqueFor);
        $this->assertLessThanOrEqual(1800, $job->uniqueFor);
    }
}
