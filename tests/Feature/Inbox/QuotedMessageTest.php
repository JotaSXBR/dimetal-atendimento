<?php

namespace Tests\Feature\Inbox;

use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Models\Channel;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Models\WahaEvent;
use App\Services\Inbox\IngestMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** A citação que o cliente manda: o id da mensagem citada e um trecho, no formato real do GOWS e no do WAHA. */
class QuotedMessageTest extends TestCase
{
    use RefreshDatabase;

    private const PEER = '5516997524950@c.us';

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        $this->freezeTime();
        User::factory()->create(['name' => 'Ana Lima']);
        $this->channel = Channel::factory()->connected()->create(['waha_session' => 'main']);
    }

    /** @param array<string, mixed> $extra */
    private function ingest(string $id, array $extra = []): Message
    {
        $event = WahaEvent::create([
            'waha_event_id' => 'live:'.$id,
            'channel_id' => $this->channel->id,
            'event' => 'message',
            'session' => 'main',
            'payload' => ['event' => 'message', 'session' => 'main', 'mode' => 'live', 'payload' => array_merge([
                'id' => 'false_'.self::PEER.'_'.$id, 'from' => self::PEER, 'fromMe' => false, 'body' => 'boa tarde',
                'timestamp' => now()->timestamp, 'hasMedia' => false, '_data' => ['Info' => ['PushName' => 'Maria']],
            ], $extra)],
            'received_at' => now(),
        ]);

        return app(IngestMessage::class)($event) ?? $this->fail('devia gravar');
    }

    /** O evento cru do GOWS, como o log do WAHA mostra: o contextInfo dentro do tipo da mensagem. */
    private function rawContext(string $stanzaId, mixed $quoted, string $type = 'extendedTextMessage'): array
    {
        return ['_data' => ['Info' => ['PushName' => 'Maria'], 'Message' => [$type => ['text' => 'boa tarde', 'contextInfo' => [
            'stanzaID' => $stanzaId, 'participant' => '1727000000000@lid', 'quotedMessage' => $quoted,
        ]]]]];
    }

    public function test_citacao_do_gows_de_mensagem_nossa_guarda_o_id_e_o_trecho_local(): void
    {
        $author = User::query()->sole();
        $mine = Message::factory()->for($this->ingestTicket())->create([
            'direction' => MessageDirection::OUT, 'origin' => MessageOrigin::PAINEL, 'author_user_id' => $author->id, 'wa_message_id' => '3EB0AAAA', 'body' => 'Opa, tudo bem?',
        ]);

        $reply = $this->ingest('Q1', $this->rawContext('3EB0AAAA', ['conversation' => '```Ana```\n\nOpa, tudo bem?']));

        $this->assertSame('3EB0AAAA', $reply->quoted_wa_message_id);
        $this->assertSame(['author' => 'Ana Lima', 'type' => 'text', 'excerpt' => 'Opa, tudo bem?'], $reply->quoted_snapshot);
        $this->assertNotNull($mine->id);
    }

    public function test_citacao_no_formato_normalizado_do_waha(): void
    {
        $reply = $this->ingest('Q2', ['replyTo' => ['id' => '3EB0BBBB', 'participant' => '1727000000000@lid', 'body' => 'texto da citada']]);

        $this->assertSame('3EB0BBBB', $reply->quoted_wa_message_id);
        $this->assertSame('texto da citada', $reply->quoted_snapshot['excerpt']);
    }

    public function test_citada_desconhecida_usa_o_texto_que_veio_no_evento_e_corta_em_200(): void
    {
        $reply = $this->ingest('Q3', $this->rawContext('3EB0CCCC', ['conversation' => str_repeat('b', 500)]));

        $this->assertSame('3EB0CCCC', $reply->quoted_wa_message_id);
        $this->assertSame(200, mb_strlen($reply->quoted_snapshot['excerpt']));
    }

    public function test_citada_de_midia_sem_texto_ganha_um_rotulo(): void
    {
        $reply = $this->ingest('Q4', $this->rawContext('3EB0DDDD', ['imageMessage' => ['mimetype' => 'image/jpeg']]));

        $this->assertSame('Mensagem', $reply->quoted_snapshot['excerpt']);
    }

    public function test_citacao_de_midia_do_cliente_tambem_e_lida(): void
    {
        $reply = $this->ingest('Q5', $this->rawContext('3EB0EEEE', ['conversation' => 'x'], 'imageMessage'));

        $this->assertSame('3EB0EEEE', $reply->quoted_wa_message_id);
    }

    public function test_formato_estranho_vira_mensagem_sem_citacao_e_sem_erro(): void
    {
        $shapes = [
            ['replyTo' => 'texto'], ['replyTo' => ['id' => 123]], ['replyTo' => []],
            ['_data' => ['Message' => 'x']], ['_data' => ['Message' => ['extendedTextMessage' => ['contextInfo' => ['stanzaID' => ['a']]]]]],
            ['_data' => ['Message' => ['extendedTextMessage' => ['contextInfo' => 'x']]]],
        ];

        foreach ($shapes as $i => $shape) {
            $reply = $this->ingest('W'.$i, $shape);

            $this->assertNull($reply->quoted_wa_message_id, 'forma '.$i);
            $this->assertNull($reply->quoted_snapshot);
        }
    }

    public function test_mensagem_sem_citacao_continua_igual(): void
    {
        $this->assertNull($this->ingest('N1')->quoted_wa_message_id);
    }

    private function ingestTicket(): Ticket
    {
        return $this->ingest('SEED1')->ticket()->firstOrFail();
    }
}
