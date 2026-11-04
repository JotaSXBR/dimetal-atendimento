<?php

namespace Tests\Feature\Inbox;

use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\WahaEvent;
use App\Services\Inbox\IngestMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IngestMultiChannelTest extends TestCase
{
    use RefreshDatabase;

    private const PEER = '5516997524950@c.us';

    /** @param  array<string, mixed>  $payload */
    private function ingest(Channel $channel, array $payload): ?Message
    {
        $event = WahaEvent::create([
            'waha_event_id' => 'evt_'.uniqid(),
            'channel_id' => $channel->id,
            'event' => 'message',
            'session' => $channel->waha_session,
            'payload' => ['event' => 'message', 'session' => $channel->waha_session, 'payload' => $payload],
            'received_at' => now(),
        ]);

        return app(IngestMessage::class)($event);
    }

    public function test_mesmo_cliente_em_dois_canais_tem_dois_tickets_sem_misturar_mensagens(): void
    {
        $first = Channel::factory()->connected()->create(['waha_session' => 'main']);
        $second = Channel::factory()->connected()->create(['waha_session' => 'second']);

        $this->ingest($first, ['id' => 'false_A1', 'from' => self::PEER, 'fromMe' => false, 'body' => 'oi no primeiro', 'timestamp' => now()->timestamp]);
        $this->ingest($second, ['id' => 'false_B1', 'from' => self::PEER, 'fromMe' => false, 'body' => 'oi no segundo', 'timestamp' => now()->timestamp]);

        $this->assertSame(1, Contact::query()->count());
        $this->assertSame(2, Ticket::query()->count());
        $this->assertSame(['oi no primeiro'], Message::query()->where('channel_id', $first->id)->pluck('body')->all());
        $this->assertSame(['oi no segundo'], Message::query()->where('channel_id', $second->id)->pluck('body')->all());
        $this->assertSame(2, Ticket::query()->distinct()->count('channel_id'));
    }

    /** @return array<string, array{string}> */
    public static function ignoredChats(): array
    {
        return [
            'newsletter' => ['120363000000000000@newsletter'],
            'lista de transmissão' => ['1700000000@broadcast'],
            'status' => ['status@broadcast'],
        ];
    }

    #[DataProvider('ignoredChats')]
    public function test_chat_de_tipo_desconhecido_nao_cria_contato_nem_ticket(string $chat): void
    {
        $channel = Channel::factory()->connected()->create(['waha_session' => 'main']);

        $this->ingest($channel, ['id' => 'false_X1', 'from' => $chat, 'fromMe' => false, 'body' => 'promoção', 'timestamp' => now()->timestamp]);

        $this->assertSame(0, Contact::query()->count());
        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(0, Message::query()->count());
    }
}
