<?php

namespace Tests\Feature\Inbox;

use App\Enums\DeliveryStatus;
use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Enums\TicketStatus;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\WahaEvent;
use App\Services\Inbox\IngestMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** O formato da listagem `chats/all/messages` (capturado no beta) difere do webhook: enviada por nós vem com `to: null`. */
class ListingShapeTest extends TestCase
{
    use RefreshDatabase;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->channel = Channel::factory()->connected()->create(['waha_session' => 'main']);
    }

    /** @return array<string, mixed> */
    private function sentByUs(string $chat, string $id = '3EB0A656AE6A8236C96A0E'): array
    {
        return [
            'id' => 'true_'.$chat.'_'.$id, 'timestamp' => 1793706298, 'from' => $chat, 'fromMe' => true, 'source' => 'api',
            'body' => "*_Atendente - Vendas @ Empresa_*\n\nBom dia", 'to' => null, 'participant' => null, 'hasMedia' => false, 'media' => null,
            'ack' => 3, 'ackName' => 'READ', 'replyTo' => null,
            '_data' => ['Info' => ['Chat' => str_replace('@c.us', '@s.whatsapp.net', $chat), 'IsFromMe' => true, 'IsGroup' => false, 'PushName' => '', 'RecipientAlt' => '']],
        ];
    }

    private function ingest(array $payload, string $mode = 'recovered'): ?Message
    {
        $event = WahaEvent::create([
            'waha_event_id' => $mode.':'.$payload['id'], 'channel_id' => $this->channel->id, 'event' => 'message', 'session' => 'main',
            'payload' => ['event' => 'message', 'session' => 'main', 'payload' => $payload, 'mode' => $mode], 'received_at' => now(),
        ]);

        return app(IngestMessage::class)($event);
    }

    public function test_enviada_por_nos_na_listagem_entra_no_chat_do_cliente(): void
    {
        $message = $this->ingest($this->sentByUs('5516999922569@c.us'));

        $this->assertNotNull($message);
        $this->assertSame(MessageDirection::OUT, $message->direction);
        $this->assertSame(MessageOrigin::FORA_DO_PAINEL, $message->origin);
        $this->assertSame(DeliveryStatus::READ, $message->delivery_status);
        $this->assertSame('5516999922569@c.us', Contact::query()->sole()->wa_id);
        $this->assertSame(TicketStatus::EM_ATENDIMENTO, Ticket::query()->sole()->status);
    }

    public function test_enviada_por_nos_em_chat_lid_usa_o_chat_da_listagem(): void
    {
        $payload = $this->sentByUs('5516999922569@c.us');
        $payload['from'] = null;
        $payload['_data']['Info']['Chat'] = '5516999922569@s.whatsapp.net';

        $this->assertNotNull($this->ingest($payload));
        $this->assertSame('5516999922569@c.us', Contact::query()->sole()->wa_id);
    }

    public function test_enviada_por_nos_no_formato_do_webhook_continua_valendo(): void
    {
        $payload = $this->sentByUs('5516999922569@c.us');
        $payload['from'] = '5516999990001@c.us';
        $payload['to'] = '5516999922569@c.us';

        $this->assertNotNull($this->ingest($payload, 'live'));
        $this->assertSame('5516999922569@c.us', Contact::query()->sole()->wa_id);
    }
}
