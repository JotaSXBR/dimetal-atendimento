<?php

namespace Tests\Feature\Inbox;

use App\Enums\ChannelStatus;
use App\Enums\DeliveryStatus;
use App\Enums\TicketStatus;
use App\Jobs\DrainOutboxJob;
use App\Jobs\HandleWahaEvent;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WahaEvent;
use App\Services\Inbox\DrainOutbox;
use App\Services\Inbox\SendFailure;
use App\Services\Inbox\SendTicketMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class OutboxDrainTest extends TestCase
{
    use RefreshDatabase;

    private const PEER = '5516997524950@c.us';

    private Channel $channel;

    private Ticket $ticket;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
        Sleep::fake();
        Queue::fake();
        Http::preventStrayRequests();

        $this->channel = Channel::factory()->connected()->create(['waha_session' => 'main']);
        $this->ticket = Ticket::factory()->for(Contact::factory()->create(['wa_id' => self::PEER]))->for($this->channel)->create(['status' => TicketStatus::EM_ATENDIMENTO]);
        $this->author = User::factory()->create();
    }

    private function queued(string $text): Message
    {
        $message = app(SendTicketMessage::class)->create($this->ticket, $this->author, $text);
        $message->update(['delivery_status' => DeliveryStatus::QUEUED]);

        return $message;
    }

    /** @param  callable(Request, int): mixed  $sendText  recebe o pedido e o número do envio (1, 2, ...) */
    private function fakeWaha(callable $sendText): void
    {
        $count = 0;

        Http::fake(function (Request $r) use ($sendText, &$count) {
            if (! str_ends_with($r->url(), '/api/sendText')) {
                return Http::response([], 201);
            }

            return $sendText($r, ++$count);
        });
    }

    private function sendTextCount(): int
    {
        return Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/api/sendText'))->count();
    }

    public function test_envia_as_aguardando_em_ordem_e_marca_enviadas(): void
    {
        $this->fakeWaha(fn (Request $r, int $n) => Http::response(['id' => "true_5516997524950@c.us_ID{$n}"], 201));
        $first = $this->queued('primeira');
        $second = $this->queued('segunda');

        app(DrainOutbox::class)($this->channel);

        $this->assertSame(DeliveryStatus::SENT, $first->fresh()?->delivery_status);
        $this->assertSame('ID1', $first->fresh()?->wa_message_id);
        $this->assertSame(DeliveryStatus::SENT, $second->fresh()?->delivery_status);
        $this->assertSame('ID2', $second->fresh()?->wa_message_id);
    }

    public function test_para_quando_a_sessao_cai_de_novo_e_nao_tenta_as_seguintes(): void
    {
        $this->fakeWaha(fn () => Http::response(['message' => 'Session status is not as expected'], 422));
        $first = $this->queued('primeira');
        $second = $this->queued('segunda');

        app(DrainOutbox::class)($this->channel);

        $this->assertSame(DeliveryStatus::QUEUED, $first->fresh()?->delivery_status);
        $this->assertSame(DeliveryStatus::QUEUED, $second->fresh()?->delivery_status);
        $this->assertSame(1, $this->sendTextCount());
    }

    public function test_uma_recusa_com_motivo_nao_trava_as_outras_conversas(): void
    {
        $this->fakeWaha(fn (Request $r, int $n) => $n === 1
            ? Http::response(['message' => 'recipient is not on WhatsApp'], 500)
            : Http::response(['id' => 'true_5516997524950@c.us_OK'], 201));
        $first = $this->queued('primeira');
        $second = $this->queued('segunda');

        app(DrainOutbox::class)($this->channel);

        $this->assertSame(DeliveryStatus::FAILED, $first->fresh()?->delivery_status);
        $this->assertSame(SendFailure::REFUSED, $first->fresh()?->failure_code);
        $this->assertSame(DeliveryStatus::SENT, $second->fresh()?->delivery_status);
    }

    public function test_expira_a_que_esperou_mais_de_24_horas_sem_enviar(): void
    {
        Http::fake();
        $old = $this->queued('velha');
        Message::query()->whereKey($old->id)->update(['created_at' => now()->subHours(25)]);

        app(DrainOutbox::class)($this->channel);

        $fresh = $old->fresh();
        $this->assertSame(DeliveryStatus::FAILED, $fresh?->delivery_status);
        $this->assertSame(SendFailure::EXPIRED, $fresh->failure_code);
        $this->assertStringContainsString('24 h', (string) $fresh->failure_reason);
        Http::assertNothingSent();
    }

    public function test_canal_fora_de_working_nao_envia_nada_mas_ainda_expira(): void
    {
        Http::fake();
        $this->channel->update(['status' => ChannelStatus::STOPPED]);
        $waiting = $this->queued('espera');
        $old = $this->queued('velha');
        Message::query()->whereKey($old->id)->update(['created_at' => now()->subHours(25)]);

        app(DrainOutbox::class)($this->channel);

        $this->assertSame(DeliveryStatus::QUEUED, $waiting->fresh()?->delivery_status);
        $this->assertSame(SendFailure::EXPIRED, $old->fresh()?->failure_code);
        Http::assertNothingSent();
    }

    public function test_esvazia_em_lotes_e_se_agenda_de_novo_quando_sobra(): void
    {
        $this->fakeWaha(fn (Request $r, int $n) => Http::response(['id' => "true_5516997524950@c.us_L{$n}"], 201));

        foreach (range(1, DrainOutbox::BATCH + 2) as $i) {
            $this->queued("m{$i}");
        }

        (new DrainOutboxJob($this->channel->id))->handle(app(DrainOutbox::class));

        $this->assertSame(DrainOutbox::BATCH, $this->sendTextCount());
        Queue::assertPushedOn('outbound', DrainOutboxJob::class);
    }

    public function test_o_comando_agenda_a_rotina_so_para_canal_conectado_com_mensagem_aguardando(): void
    {
        $this->queued('espera');
        $offline = Channel::factory()->create(['waha_session' => 'off', 'status' => ChannelStatus::STOPPED]);
        $ticket = Ticket::factory()->for(Contact::factory()->create(['wa_id' => '5511900000000@c.us']))->for($offline)->create();
        app(SendTicketMessage::class)->create($ticket, $this->author, 'parada')->update(['delivery_status' => DeliveryStatus::QUEUED]);

        $this->artisan('app:drain-outbox')->assertSuccessful();

        Queue::assertPushed(DrainOutboxJob::class, 1);
        Queue::assertPushed(DrainOutboxJob::class, fn (DrainOutboxJob $job) => $job->channelId === $this->channel->id);
    }

    public function test_o_comando_recolhe_linha_presa_em_enviando_sem_dono(): void
    {
        $stuck = app(SendTicketMessage::class)->create($this->ticket, $this->author, 'presa');
        Message::query()->whereKey($stuck->id)->update(['updated_at' => now()->subMinutes(11)]);
        $recent = app(SendTicketMessage::class)->create($this->ticket, $this->author, 'recente');

        $this->artisan('app:drain-outbox')->assertSuccessful();

        $fresh = $stuck->fresh();
        $this->assertSame(DeliveryStatus::FAILED, $fresh?->delivery_status);
        $this->assertSame(SendFailure::UNKNOWN, $fresh->failure_code);
        $this->assertNull($recent->fresh()?->delivery_status);
    }

    public function test_quando_a_sessao_volta_a_working_a_fila_e_esvaziada(): void
    {
        $this->channel->update(['status' => ChannelStatus::STOPPED]);

        $this->statusEvent('WORKING');

        Queue::assertPushedOn('outbound', DrainOutboxJob::class, fn (DrainOutboxJob $job) => $job->channelId === $this->channel->id);
    }

    public function test_status_working_repetido_nao_agenda_de_novo(): void
    {
        $this->statusEvent('WORKING');

        Queue::assertNotPushed(DrainOutboxJob::class);
    }

    private function statusEvent(string $status): void
    {
        $event = WahaEvent::create([
            'waha_event_id' => 'evt_'.uniqid(),
            'channel_id' => $this->channel->id,
            'event' => 'session.status',
            'session' => 'main',
            'payload' => ['payload' => ['status' => $status]],
            'received_at' => now(),
        ]);

        (new HandleWahaEvent($event->id))->handle();
    }
}
