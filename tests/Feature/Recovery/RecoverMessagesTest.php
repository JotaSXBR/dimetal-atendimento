<?php

namespace Tests\Feature\Recovery;

use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\WahaEvent;
use App\Services\Inbox\IngestMessage;
use App\Services\Inbox\RecoverMessages;
use App\Services\Inbox\RecoveryResult;
use App\Services\Inbox\ResolveContact;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class RecoverMessagesTest extends TestCase
{
    use RefreshDatabase;

    private const A = '5516997524950@c.us';

    private const B = '5516981110000@c.us';

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
        $this->freezeTime();
        Queue::fake();
        $this->channel = Channel::factory()->connected()->create(['waha_session' => 'main']);
    }

    /** @return list<array<string, mixed>> */
    private function window(): array
    {
        return [
            ['id' => 'false_'.self::A.'_R1', 'from' => self::A, 'fromMe' => false, 'body' => 'Quero um orcamento', 'timestamp' => now()->subHours(3)->timestamp, 'hasMedia' => false, '_data' => ['Info' => ['PushName' => 'Maria']]],
            ['id' => 'true_'.self::A.'_R2', 'from' => '5516999990001@c.us', 'to' => self::A, 'fromMe' => true, 'body' => 'Segue o orcamento', 'timestamp' => now()->subHours(2)->timestamp, 'hasMedia' => false, 'ack' => 2],
            ['id' => 'false_'.self::B.'_R3', 'from' => self::B, 'fromMe' => false, 'body' => 'Bom dia', 'timestamp' => now()->subHour()->timestamp, 'hasMedia' => false, '_data' => ['Info' => ['PushName' => 'Joao']]],
        ];
    }

    /** @param  list<array<string, mixed>>  $items */
    private function wahaReturns(array $items): void
    {
        Http::fake(['*/api/main/chats/all/messages*' => Http::response($items, 200)]);
    }

    private function recover(): RecoveryResult
    {
        return app(RecoverMessages::class)($this->channel, now()->subHours(4), now());
    }

    public function test_cria_mensagens_e_tickets_da_janela(): void
    {
        $this->wahaReturns($this->window());

        $result = $this->recover();

        $this->assertSame(3, $result->messages);
        $this->assertSame(2, $result->conversations);
        $this->assertSame(3, Message::query()->count());
        $this->assertSame(2, Ticket::query()->count());
    }

    public function test_rodar_duas_vezes_nao_cria_nada_novo(): void
    {
        $this->wahaReturns($this->window());
        $this->recover();
        $counts = [Message::query()->count(), Ticket::query()->count(), WahaEvent::query()->count()];

        $second = $this->recover();

        $this->assertSame(0, $second->messages);
        $this->assertSame(0, $second->conversations);
        $this->assertSame($counts, [Message::query()->count(), Ticket::query()->count(), WahaEvent::query()->count()]);
    }

    public function test_mensagem_que_falha_nao_derruba_as_outras(): void
    {
        $this->app->bind(IngestMessage::class, fn () => new class(app(ResolveContact::class)) extends IngestMessage
        {
            public function __invoke(WahaEvent $event): ?Message
            {
                if (str_ends_with($event->waha_event_id, ':R1')) {
                    throw new RuntimeException('falha simulada');
                }

                return parent::__invoke($event);
            }
        });
        $this->wahaReturns($this->window());

        $result = $this->recover();

        $this->assertSame(2, $result->messages);
        $this->assertSame(2, Message::query()->count());
    }

    public function test_janela_maior_que_7_dias_e_cortada(): void
    {
        $this->wahaReturns([]);

        app(RecoverMessages::class)($this->channel, now()->subDays(10), now());

        Http::assertSent(fn ($request) => (int) $request['filter.timestamp.gte'] === now()->subDays(7)->timestamp);
    }

    public function test_eco_de_mensagem_do_painel_nao_duplica(): void
    {
        Http::fake(['*/api/main/chats/all/messages*' => Http::sequence()->push([$this->window()[0]], 200)->push($this->window(), 200)]);
        $this->recover();
        // O painel gravou a resposta antes de o WAHA devolver o eco.
        Message::factory()->for(Ticket::query()->sole())->create([
            'direction' => MessageDirection::OUT, 'origin' => MessageOrigin::PAINEL, 'wa_message_id' => null,
            'body' => 'Segue o orcamento', 'delivery_status' => null, 'sent_at' => now()->subHours(2),
        ]);
        $this->recover();

        $out = Message::query()->where('direction', MessageDirection::OUT->value)->get();
        $this->assertCount(1, $out);
        $this->assertSame('R2', $out->sole()->wa_message_id);
    }
}
