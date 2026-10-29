<?php

namespace Tests\Feature\Inbox;

use App\Enums\ChannelStatus;
use App\Enums\DeliveryStatus;
use App\Enums\TicketStatus;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WahaEvent;
use App\Services\Inbox\IngestMessage;
use App\Services\Inbox\SendFailure;
use App\Services\Inbox\SendTicketMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class OutboxDeliveryTest extends TestCase
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

    private function create(string $text = 'oi'): Message
    {
        return app(SendTicketMessage::class)->create($this->ticket, $this->author, $text);
    }

    private function deliver(Message $message): void
    {
        app(SendTicketMessage::class)->deliver($message);
    }

    private function fakeWaha(callable $sendText): void
    {
        Http::fake(fn (Request $r) => str_ends_with($r->url(), '/api/sendText') ? $sendText($r) : Http::response([], 201));
    }

    public function test_canal_fora_de_working_deixa_a_mensagem_aguardando_sem_chamar_o_waha(): void
    {
        $this->channel->update(['status' => ChannelStatus::STOPPED]);
        Http::fake();
        $message = $this->create();

        $this->deliver($message);

        $this->assertSame(DeliveryStatus::QUEUED, $message->fresh()?->delivery_status);
        Http::assertNothingSent();
    }

    public function test_mensagem_nova_espera_atras_de_uma_aguardando_mais_antiga_para_manter_a_ordem(): void
    {
        Http::fake();
        $first = $this->create('primeira');
        $first->update(['delivery_status' => DeliveryStatus::QUEUED]);
        $second = $this->create('segunda');

        $this->deliver($second);

        $this->assertSame(DeliveryStatus::QUEUED, $second->fresh()?->delivery_status);
        Http::assertNothingSent();
    }

    public function test_sessao_caiu_durante_o_envio_vira_aguardando_e_nao_falha(): void
    {
        $this->fakeWaha(fn () => Http::response(['message' => 'Session status is not as expected. Try again later or restart the session'], 422));
        $message = $this->create();

        $this->deliver($message);

        $fresh = $message->fresh();
        $this->assertSame(DeliveryStatus::QUEUED, $fresh?->delivery_status);
        $this->assertNull($fresh->failure_code);
    }

    public function test_mensagem_aguardando_e_enviada_quando_a_sessao_volta(): void
    {
        $this->fakeWaha(fn () => Http::response(['id' => 'true_5516997524950@c.us_OK'], 201));
        $message = $this->create();
        $message->update(['delivery_status' => DeliveryStatus::QUEUED]);

        $this->deliver($message);

        $fresh = $message->fresh();
        $this->assertSame(DeliveryStatus::SENT, $fresh?->delivery_status);
        $this->assertSame('OK', $fresh->wa_message_id);
    }

    public function test_timeout_marca_falha_desconhecida_com_aviso_de_que_pode_ter_saido(): void
    {
        $this->fakeWaha(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 15001 milliseconds'));
        $message = $this->create();

        try {
            $this->deliver($message);
            $this->fail('Deveria relançar');
        } catch (ConnectionException) {
            $fresh = $message->fresh();
            $this->assertSame(DeliveryStatus::FAILED, $fresh?->delivery_status);
            $this->assertSame(SendFailure::UNKNOWN, $fresh->failure_code);
            $this->assertStringContainsString('pode ter sido enviada', (string) $fresh->failure_reason);
        }
    }

    public function test_limite_do_whatsapp_marca_falha_sem_reenvio(): void
    {
        $this->fakeWaha(fn () => Http::response(['message' => '2 UNKNOWN: error 463 tc-token'], 500));
        $message = $this->create();

        try {
            $this->deliver($message);
            $this->fail('Deveria relançar');
        } catch (\Throwable) {
            $this->assertSame(SendFailure::RATE_LIMIT, $message->fresh()?->failure_code);
        }
    }

    public function test_eco_tardio_cura_a_falha_desconhecida_na_mesma_linha(): void
    {
        $message = $this->create('Segue o orcamento');
        $message->update(['delivery_status' => DeliveryStatus::FAILED, 'failure_code' => SendFailure::UNKNOWN, 'failure_reason' => 'Sem resposta']);

        $this->echoOf('Segue o orcamento', 'true_5516997524950@c.us_ECO');

        $fresh = $message->fresh();
        $this->assertSame(DeliveryStatus::SENT, $fresh?->delivery_status);
        $this->assertSame('ECO', $fresh->wa_message_id);
        $this->assertNull($fresh->failure_reason);
        $this->assertNull($fresh->failure_code);
        $this->assertSame(1, Message::query()->count());
    }

    public function test_eco_fora_da_janela_da_ultima_tentativa_nao_adota_a_linha(): void
    {
        $message = $this->create('Segue o orcamento');
        $message->update(['delivery_status' => DeliveryStatus::FAILED, 'failure_code' => SendFailure::UNKNOWN]);
        Message::query()->whereKey($message->id)->update(['updated_at' => now()->subMinutes(5)]);

        $this->echoOf('Segue o orcamento', 'true_5516997524950@c.us_TARDE');

        $this->assertNull($message->fresh()?->wa_message_id);
        $this->assertSame(2, Message::query()->count());
    }

    public function test_eco_nao_adota_mensagem_que_ainda_esta_aguardando_conexao(): void
    {
        $message = $this->create('Segue o orcamento');
        $message->update(['delivery_status' => DeliveryStatus::QUEUED]);

        $this->echoOf('Segue o orcamento', 'true_5516997524950@c.us_OUTRA');

        $this->assertNull($message->fresh()?->wa_message_id);
        $this->assertSame(DeliveryStatus::QUEUED, $message->fresh()?->delivery_status);
    }

    private function echoOf(string $body, string $id): void
    {
        $event = WahaEvent::create([
            'waha_event_id' => 'evt_'.uniqid(),
            'channel_id' => $this->channel->id,
            'event' => 'message.any',
            'session' => 'main',
            'payload' => ['payload' => ['id' => $id, 'from' => '5516999990001@c.us', 'to' => self::PEER, 'fromMe' => true, 'body' => $body, 'timestamp' => now()->timestamp, 'hasMedia' => false]],
            'received_at' => now(),
        ]);

        app(IngestMessage::class)($event);
    }
}
