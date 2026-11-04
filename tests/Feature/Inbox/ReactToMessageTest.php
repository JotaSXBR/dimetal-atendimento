<?php

namespace Tests\Feature\Inbox;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageReaction;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Models\WahaEvent;
use App\Services\Inbox\ApplyMessageChange;
use App\Services\Inbox\MessageActionFailed;
use App\Services\Inbox\ReactToMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Reagir pelo painel: o WAHA recebe o emoji, o evento de auditoria fica, e a reação só entra no painel pelo eco. */
class ReactToMessageTest extends TestCase
{
    use RefreshDatabase;

    private const PEER = '5516997524950@c.us';

    private Channel $channel;

    private User $author;

    private Message $original;

    private int $wahaStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
        Http::fake(fn () => Http::response([], $this->wahaStatus));

        $this->channel = Channel::factory()->connected()->create(['waha_session' => 'main']);
        $this->author = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $ticket = Ticket::factory()
            ->for(Contact::factory()->create(['wa_id' => self::PEER]))
            ->for($this->channel)
            ->create(['status' => TicketStatus::EM_ATENDIMENTO, 'assigned_user_id' => $this->author->id]);
        $this->original = Message::factory()->for($ticket)->create(['wa_message_id' => 'ORIG1']);
    }

    private function react(string $emoji, ?Message $message = null): void
    {
        app(ReactToMessage::class)($message ?? $this->original, $this->author, $emoji);
    }

    public function test_emoji_da_lista_chama_o_waha_e_grava_o_evento_sem_gravar_a_reacao(): void
    {
        $this->react('👍');

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['messageId'] === 'false_'.self::PEER.'_ORIG1' && $r['reaction'] === '👍');
        $event = TicketEvent::query()->where('type', 'message_reacted')->sole();
        $this->assertSame($this->author->id, $event->actor_user_id);
        $this->assertSame(['message_id' => $this->original->id, 'emoji' => '👍'], $event->payload);
        $this->assertSame(0, MessageReaction::count(), 'a reação entra pelo eco, não pelo clique');
    }

    public function test_mesmo_emoji_de_novo_manda_vazio_para_remover(): void
    {
        MessageReaction::create(['message_id' => $this->original->id, 'by' => 'me', 'emoji' => '👍', 'reacted_at' => now()]);

        $this->react('👍');

        Http::assertSent(fn (Request $r) => $r['reaction'] === '');
        $this->assertNull(TicketEvent::query()->where('type', 'message_reacted')->sole()->payload['emoji']);
    }

    public function test_emoji_fora_da_lista_e_recusado_sem_chamar_o_waha(): void
    {
        $this->expectException(MessageActionFailed::class);

        try {
            $this->react('🔥');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_mensagem_sem_wa_message_id_e_recusada_sem_chamar_o_waha(): void
    {
        $pending = Message::factory()->for($this->original->ticket)->create(['wa_message_id' => null]);

        try {
            $this->react('👍', $pending);
            $this->fail('devia recusar');
        } catch (MessageActionFailed $e) {
            $this->assertStringContainsString('ainda não foi enviada', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_recusa_do_waha_nao_grava_evento_nem_altera_o_painel(): void
    {
        $this->wahaStatus = 400;

        try {
            $this->react('👍');
            $this->fail('devia recusar');
        } catch (MessageActionFailed) {
            // esperado
        }

        $this->assertSame(0, TicketEvent::query()->where('type', 'message_reacted')->count());
        $this->assertSame(0, MessageReaction::count());
    }

    public function test_o_eco_da_reacao_do_painel_grava_a_reacao_do_numero_da_empresa(): void
    {
        $event = WahaEvent::create([
            'waha_event_id' => 'evt_echo1',
            'channel_id' => $this->channel->id,
            'event' => 'message.reaction',
            'session' => 'main',
            'payload' => ['event' => 'message.reaction', 'session' => 'main', 'payload' => [
                'id' => 'true_'.self::PEER.'_RX1', 'from' => self::PEER, 'fromMe' => true, 'timestamp' => now()->timestamp,
                'reaction' => ['text' => '👍', 'messageId' => 'false_'.self::PEER.'_ORIG1'],
            ]],
            'received_at' => now(),
        ]);

        $this->assertTrue(app(ApplyMessageChange::class)->reaction($event));

        $reaction = MessageReaction::query()->sole();
        $this->assertSame(['me', '👍'], [$reaction->by, $reaction->emoji]);
    }
}
