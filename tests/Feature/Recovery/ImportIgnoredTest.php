<?php

namespace Tests\Feature\Recovery;

use App\Jobs\ImportHistoryJob;
use App\Livewire\Alerts\Bell;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Notifications\MessagesRecovered;
use App\Services\Inbox\ImportHistory;
use App\Services\Inbox\RecoveryResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class ImportIgnoredTest extends TestCase
{
    use RefreshDatabase;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
        $this->travelTo(now()->startOfSecond());
        Queue::fake();
        $this->channel = Channel::factory()->connected()->create(['waha_session' => 'main', 'backfill_days' => 1]);
    }

    /** @return array<string, mixed> */
    private function msg(string $from, string $id, string $body = 'oi'): array
    {
        return ['id' => 'false_'.$from.'_'.$id, 'from' => $from, 'fromMe' => false, 'body' => $body, 'timestamp' => now()->subHours(2)->getTimestamp(), 'hasMedia' => false];
    }

    public function test_conta_o_que_ignora_por_motivo(): void
    {
        Http::fake(['*/api/main/chats/all/messages*' => Http::response([
            $this->msg('120363@g.us', 'G1'), $this->msg('120363@g.us', 'G2'),
            $this->msg('status@broadcast', 'B1'),
            $this->msg('5516997524950@c.us', 'V1', ''),
            $this->msg('5516997524950@c.us', 'N1'),
        ], 200)]);

        $result = app(ImportHistory::class)($this->channel);

        $this->assertSame(['grupo' => 2, 'transmissão' => 1, 'sem conteúdo' => 1], $result->ignored);
        $this->assertSame(1, Message::query()->count());
    }

    public function test_aviso_final_leva_os_ignorados_da_ultima_rodada(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $service = Mockery::mock(ImportHistory::class);
        $service->shouldReceive('__invoke')->once()->andReturn(new RecoveryResult(3, 1, ['grupo' => 2]));
        $service->open = 0;
        $service->complete = true;
        $this->instance(ImportHistory::class, $service);

        app()->call([new ImportHistoryJob($this->channel->id), 'handle']);

        Notification::assertSentTo($user, MessagesRecovered::class, fn (MessagesRecovered $n): bool => $n->toArray($user)['ignored'] === ['grupo' => 2]);
    }

    public function test_sino_mostra_os_ignorados(): void
    {
        $user = User::factory()->create();
        $user->notify(new MessagesRecovered($this->channel, 5, 2, 'imported', 0, null, ['grupo' => 3, 'sem conteúdo' => 1]));

        Livewire::actingAs($user)->test(Bell::class)->assertSee('ignoradas: 3 de grupo, 1 sem conteúdo');
    }
}
