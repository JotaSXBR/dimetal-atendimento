<?php

namespace Tests\Feature\Inbox;

use App\Enums\DeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Jobs\SendTicketMessageJob;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WahaEvent;
use App\Services\Inbox\IngestMessage;
use App\Services\Inbox\SendTicketMessage;
use App\Services\Waha\WahaRequestException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class SendTicketMessageTest extends TestCase
{
    use RefreshDatabase;

    private const PEER = '5516997524950@c.us';

    private const SENT_ID = 'true_5516997524950@c.us_SENT';

    private Channel $channel;

    private Ticket $ticket;

    private User $author;

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
        Queue::fake();
        Http::preventStrayRequests();
        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);

        $this->channel = Channel::factory()->connected()->create(['waha_session' => 'main']);
        $this->ticket = Ticket::factory()
            ->for(Contact::factory()->create(['wa_id' => self::PEER]))
            ->for($this->channel)
            ->create();
        $this->author = User::factory()->create();
    }

    private function send(string $text = 'Segue o orcamento'): Message
    {
        return app(SendTicketMessage::class)($this->ticket, $this->author, $text);
    }

    public function test_create_grava_a_linha_sem_chamar_o_waha(): void
    {
        Http::fake();

        $message = app(SendTicketMessage::class)->create($this->ticket, $this->author, 'oi');

        Http::assertNothingSent();
        $this->assertSame('oi', $message->body);
        $this->assertNull($message->delivery_status);
        $this->assertNull($message->wa_message_id);
        $this->assertSame($this->author->id, $message->author_user_id);
    }

    public function test_deliver_envia_uma_linha_ja_criada(): void
    {
        Http::fake(['*/api/sendText' => Http::response(['id' => self::SENT_ID], 201)]);
        $message = app(SendTicketMessage::class)->create($this->ticket, $this->author, 'oi');

        app(SendTicketMessage::class)->deliver($message);

        $this->assertSame(DeliveryStatus::SENT, $message->fresh()?->delivery_status);
        $this->assertSame(Message::waKey(self::SENT_ID), $message->fresh()?->wa_message_id);
    }

    public function test_job_que_morre_antes_do_envio_marca_a_mensagem_como_falha(): void
    {
        $message = app(SendTicketMessage::class)->create($this->ticket, $this->author, 'oi');

        (new SendTicketMessageJob($message->id))->failed(new \RuntimeException('worker morto'));

        $fresh = $message->fresh();
        $this->assertSame(DeliveryStatus::FAILED, $fresh?->delivery_status);
        $this->assertStringContainsString('worker morto', (string) $fresh->failure_reason);
    }

    public function test_job_que_falha_nao_sobrescreve_mensagem_ja_enviada(): void
    {
        $message = app(SendTicketMessage::class)->create($this->ticket, $this->author, 'oi');
        $message->update(['wa_message_id' => 'ABC', 'delivery_status' => DeliveryStatus::SENT]);

        (new SendTicketMessageJob($message->id))->failed(new \RuntimeException('tarde demais'));

        $this->assertSame(DeliveryStatus::SENT, $message->fresh()?->delivery_status);
    }

    public function test_fila_de_saida_espera_mais_que_o_timeout_do_job(): void
    {
        $this->assertGreaterThan((new SendTicketMessageJob(1))->timeout, config()->integer('queue.connections.redis.retry_after'));
    }

    public function test_grava_a_linha_antes_de_chamar_o_waha(): void
    {
        $pendingWhenCalled = null;

        Http::fake(function () use (&$pendingWhenCalled) {
            $pendingWhenCalled = Message::query()->whereNull('wa_message_id')->where('origin', MessageOrigin::PAINEL->value)->count();

            return Http::response(['id' => self::SENT_ID], 201);
        });

        $this->send();

        $this->assertSame(1, $pendingWhenCalled);
    }

    public function test_preenche_o_wa_message_id_devolvido_e_registra_o_autor(): void
    {
        Http::fake(['*/api/sendText' => Http::response(['id' => self::SENT_ID], 201)]);

        $message = $this->send();

        $this->assertSame(Message::waKey(self::SENT_ID), $message->fresh()?->wa_message_id);
        $this->assertSame(MessageDirection::OUT, $message->direction);
        $this->assertSame(MessageOrigin::PAINEL, $message->origin);
        $this->assertSame($this->author->id, $message->author_user_id);
        $this->assertSame(DeliveryStatus::SENT, $message->fresh()?->delivery_status);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/api/sendText') && $request['chatId'] === self::PEER && $request['text'] === 'Segue o orcamento');
        $this->assertSame('message_out', $this->ticket->events()->latest('id')->value('type'));
    }

    public function test_marca_first_human_reply_at_apenas_na_primeira_resposta(): void
    {
        Http::fake(['*/api/sendText' => Http::sequence()
            ->push(['id' => 'true_a@c.us_1'], 201)
            ->push(['id' => 'true_a@c.us_2'], 201)]);

        $this->send('um');
        $first = $this->ticket->fresh()?->first_human_reply_at;
        $this->travel(5)->minutes();
        $this->send('dois');

        $this->assertNotNull($first);
        $this->assertTrue($first->equalTo($this->ticket->fresh()?->first_human_reply_at));
        $this->assertTrue($this->ticket->fresh()?->last_outbound_at?->greaterThan($first));
    }

    public function test_falha_do_waha_marca_failed_e_preserva_o_texto(): void
    {
        Http::fake(['*/api/sendText' => Http::response(['message' => 'Session not found'], 500)]);

        try {
            $this->send('texto importante');
            $this->fail('Deveria lançar WahaRequestException');
        } catch (WahaRequestException) {
            $message = Message::query()->sole();

            $this->assertSame(DeliveryStatus::FAILED, $message->delivery_status);
            $this->assertSame('texto importante', $message->body);
            $this->assertNull($this->ticket->fresh()?->first_human_reply_at);
        }
    }

    public function test_falha_guarda_o_motivo_que_o_waha_devolveu(): void
    {
        Http::fake(['*/api/sendText' => Http::response([
            'statusCode' => 500,
            'exception' => ['message' => '2 UNKNOWN: recipient is not on WhatsApp'],
        ], 500)]);

        try {
            $this->send('oi');
            $this->fail('Deveria lançar WahaRequestException');
        } catch (WahaRequestException) {
            $this->assertStringContainsString('recipient is not on WhatsApp', (string) Message::query()->sole()->failure_reason);
        }
    }

    public function test_ticket_desatualizado_nao_sobrescreve_first_human_reply_at(): void
    {
        $earlier = now()->subHour()->startOfSecond();
        Http::fake(function () use ($earlier) {
            Ticket::query()->whereKey($this->ticket->id)->update(['first_human_reply_at' => $earlier]);

            return Http::response(['id' => self::SENT_ID], 201);
        });

        $this->send();

        $this->assertSame($earlier->getTimestamp(), $this->ticket->fresh()?->first_human_reply_at?->getTimestamp());
    }

    public function test_eco_casado_antes_da_falha_http_nao_marca_a_mensagem_como_falha(): void
    {
        Http::fake(function ($request) {
            if (! str_ends_with($request->url(), '/api/sendText')) {
                return Http::response([], 201);
            }

            $echo = WahaEvent::create([
                'waha_event_id' => 'evt_echo2',
                'channel_id' => $this->channel->id,
                'event' => 'message.any',
                'session' => 'main',
                'payload' => ['payload' => [
                    'id' => self::SENT_ID,
                    'from' => '5516999990001@c.us',
                    'to' => self::PEER,
                    'fromMe' => true,
                    'body' => 'Segue o orcamento',
                    'timestamp' => now()->timestamp,
                ]],
                'received_at' => now(),
            ]);
            app(IngestMessage::class)($echo);

            return Http::response(['message' => 'timeout'], 504);
        });

        try {
            $this->send();
            $this->fail('Deveria relançar a falha do cliente');
        } catch (WahaRequestException) {
            $this->assertNotSame(DeliveryStatus::FAILED, Message::query()->sole()->delivery_status);
        }
    }

    public function test_eco_chegando_antes_da_resposta_do_waha_nao_duplica(): void
    {
        Http::fake(function ($request) {
            if (! str_ends_with($request->url(), '/api/sendText')) {
                return Http::response([], 201);
            }

            $echo = WahaEvent::create([
                'waha_event_id' => 'evt_echo',
                'channel_id' => $this->channel->id,
                'event' => 'message.any',
                'session' => 'main',
                'payload' => ['payload' => [
                    'id' => self::SENT_ID,
                    'from' => '5516999990001@c.us',
                    'to' => self::PEER,
                    'fromMe' => true,
                    'body' => 'Segue o orcamento',
                    'timestamp' => now()->timestamp,
                ]],
                'received_at' => now(),
            ]);
            app(IngestMessage::class)($echo);

            return Http::response(['id' => self::SENT_ID], 201);
        });

        $message = $this->send();

        $this->assertSame(1, Message::query()->count());
        $this->assertSame(Message::waKey(self::SENT_ID), $message->fresh()?->wa_message_id);
        $this->assertSame(MessageOrigin::PAINEL, $message->fresh()?->origin);
    }
}
