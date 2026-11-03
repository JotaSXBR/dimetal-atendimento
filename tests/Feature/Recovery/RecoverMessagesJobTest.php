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

    public function test_canal_inativo_ou_fora_de_working_nao_recupera(): void
    {
        $this->serviceNeverRuns();

        $this->runJob(Channel::factory()->connected()->create(['active' => false]));
        $this->runJob(Channel::factory()->create(['status' => ChannelStatus::STARTING, 'waha_session' => 'outra']));

        Notification::assertNothingSent();
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
}
