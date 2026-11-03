<?php

namespace Tests\Feature\Alertas;

use App\Enums\ChannelStatus;
use App\Jobs\ConfirmChannelDownJob;
use App\Models\Channel;
use App\Models\User;
use App\Notifications\ChannelDown;
use App\Notifications\ChannelUp;
use App\Support\SidebarState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** A faixa, o som e o sino só reagem a uma queda confirmada: o WhatsApp oscila por ~1 s várias vezes ao dia. */
class ChannelDownGraceTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $t0;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->t0 = CarbonImmutable::now()->startOfSecond();
        $this->travelTo($this->t0);
        Notification::fake();
        Queue::fake();
        $this->user = User::factory()->create();
    }

    private function at(int $seconds): void
    {
        $this->travelTo($this->t0->addSeconds($seconds));
    }

    /** Roda, na ordem, os jobs de confirmação que a fila falsa recebeu. */
    private function runConfirmJobs(): void
    {
        foreach (Queue::pushed(ConfirmChannelDownJob::class)->all() as $job) {
            app()->call([$job, 'handle']);
        }
    }

    public function test_oscilacao_de_1s_nao_avisa_nem_aparece_na_faixa(): void
    {
        $channel = Channel::factory()->connected()->create();

        $channel->update(['status' => ChannelStatus::STARTING]);
        Queue::assertPushed(ConfirmChannelDownJob::class, fn (ConfirmChannelDownJob $job): bool => $job->channelId === $channel->id && $job->delay === 60);
        $this->assertSame(0, Channel::query()->confirmedDown()->count());

        $this->at(1);
        $channel->update(['status' => ChannelStatus::WORKING]);
        $this->at(61);
        $this->runConfirmJobs();

        Notification::assertNothingSent();
        $this->assertSame(0, Channel::query()->confirmedDown()->count());
    }

    public function test_queda_confirma_depois_de_60s(): void
    {
        $channel = Channel::factory()->connected()->create();
        $channel->update(['status' => ChannelStatus::STARTING]);

        $this->at(30);
        $this->runConfirmJobs();
        $this->assertSame(0, Channel::query()->confirmedDown()->count());
        $this->assertFalse(app(SidebarState::class)->whatsappDown());

        $this->at(61);
        $this->runConfirmJobs();

        Notification::assertSentTo($this->user, ChannelDown::class);
        $this->assertNotNull($channel->fresh()->down_alerted_at);
        $this->assertSame([$channel->id], Channel::query()->confirmedDown()->pluck('id')->all());
        $this->assertTrue(app(SidebarState::class)->whatsappDown());
    }

    public function test_volta_so_avisa_se_a_queda_foi_anunciada(): void
    {
        $silencioso = Channel::factory()->connected()->create(['waha_session' => 'a']);
        $silencioso->update(['status' => ChannelStatus::STARTING]);
        $silencioso->update(['status' => ChannelStatus::WORKING]);
        Notification::assertNotSentTo($this->user, ChannelUp::class);

        $anunciado = Channel::factory()->connected()->create(['waha_session' => 'b']);
        $anunciado->update(['status' => ChannelStatus::STARTING]);
        $this->at(61);
        $this->runConfirmJobs();
        $anunciado->update(['status' => ChannelStatus::WORKING]);

        Notification::assertSentToTimes($this->user, ChannelUp::class, 1);
        $this->assertNull($anunciado->fresh()->down_alerted_at);
    }

    public function test_job_da_primeira_queda_nao_alerta_a_segunda_cedo(): void
    {
        $channel = Channel::factory()->connected()->create();
        $channel->update(['status' => ChannelStatus::STARTING]);
        $this->at(5);
        $channel->update(['status' => ChannelStatus::WORKING]);
        $this->at(30);
        $channel->update(['status' => ChannelStatus::STARTING]);
        [$first, $second] = Queue::pushed(ConfirmChannelDownJob::class)->all();

        $this->at(60);
        app()->call([$first, 'handle']);
        Notification::assertNothingSent();

        $this->at(90);
        app()->call([$second, 'handle']);
        Notification::assertSentToTimes($this->user, ChannelDown::class, 1);
    }

    /** @return array<string, array{ChannelStatus}> */
    public static function urgentes(): array
    {
        return ['aguardando QR' => [ChannelStatus::SCAN_QR_CODE], 'falhou' => [ChannelStatus::FAILED]];
    }

    #[DataProvider('urgentes')]
    public function test_aguardando_qr_e_falhou_avisam_na_hora_e_o_job_tardio_nao_repete(ChannelStatus $status): void
    {
        $channel = Channel::factory()->connected()->create();
        $channel->update(['status' => ChannelStatus::STARTING]);

        $channel->update(['status' => $status]);
        Notification::assertSentToTimes($this->user, ChannelDown::class, 1);
        $this->assertSame([$channel->id], Channel::query()->confirmedDown()->pluck('id')->all());

        $this->at(61);
        $this->runConfirmJobs();

        Notification::assertSentToTimes($this->user, ChannelDown::class, 1);
    }

    public function test_canal_nunca_conectado_continua_na_faixa_na_hora(): void
    {
        $channel = Channel::factory()->create(['status' => ChannelStatus::STOPPED]);

        $this->assertSame([$channel->id], Channel::query()->confirmedDown()->pluck('id')->all());
    }

    public function test_canal_desativado_nao_alerta(): void
    {
        $channel = Channel::factory()->connected()->create(['active' => false]);

        $channel->update(['status' => ChannelStatus::STARTING]);
        $this->at(61);
        $this->runConfirmJobs();

        Queue::assertNotPushed(ConfirmChannelDownJob::class);
        Notification::assertNothingSent();
        $this->assertSame(0, Channel::query()->confirmedDown()->count());
    }
}
