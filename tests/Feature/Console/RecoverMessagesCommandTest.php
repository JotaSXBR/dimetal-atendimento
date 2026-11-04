<?php

namespace Tests\Feature\Console;

use App\Enums\ChannelStatus;
use App\Jobs\RecoverMessagesJob;
use App\Models\Channel;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RecoverMessagesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_despacha_so_para_canais_ativos_e_conectados(): void
    {
        $this->freezeTime();
        Queue::fake();
        $conectado = Channel::factory()->connected()->create(['waha_session' => 'a']);
        Channel::factory()->create(['waha_session' => 'b', 'status' => ChannelStatus::STOPPED]);
        Channel::factory()->connected()->create(['waha_session' => 'c', 'active' => false]);

        $this->artisan('app:recover-messages')->assertSuccessful();

        Queue::assertPushed(RecoverMessagesJob::class, 1);
        Queue::assertPushed(RecoverMessagesJob::class, fn (RecoverMessagesJob $job): bool => $job->channelId === $conectado->id
            && $job->from->equalTo(now()->subHours(24))
            && $job->to->equalTo(now())
            && ! $job->notifyAlways);
    }

    public function test_esta_agendado_para_as_0415(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => str_contains((string) $event->command, 'app:recover-messages'));

        $this->assertNotNull($event);
        $this->assertSame('15 4 * * *', $event->expression);
    }
}
