<?php

namespace Tests\Feature\Inbox;

use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\MessageEdit;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Models\WahaEvent;
use App\Services\Inbox\ApplyMessageChange;
use App\Services\Inbox\EditTicketMessage;
use App\Services\Inbox\MessageActionFailed;
use App\Services\Inbox\RevokeTicketMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Editar e apagar para todos: o WAHA recebe o pedido, a auditoria guarda o autor e o painel só muda pelo eco. */
class EditRevokeMessageTest extends TestCase
{
    use RefreshDatabase;

    private const PEER = '5516997524950@c.us';

    private const ROUTE = 'http://waha.test:3000/api/main/chats/5516997524950%40c.us/messages/true_5516997524950%40c.us_MINE1';

    private Channel $channel;

    private User $author;

    private Ticket $ticket;

    private Message $mine;

    private int $wahaStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeSecond();
        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
        Http::fake(fn () => Http::response([], $this->wahaStatus));

        $this->channel = Channel::factory()->connected()->create(['waha_session' => 'main']);
        $this->author = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $this->ticket = Ticket::factory()
            ->for(Contact::factory()->create(['wa_id' => self::PEER]))
            ->for($this->channel)
            ->create(['status' => TicketStatus::EM_ATENDIMENTO, 'assigned_user_id' => $this->author->id]);
        $this->mine = $this->own();
    }

    /** @param array<string, mixed> $attributes */
    private function own(array $attributes = []): Message
    {
        return Message::factory()->for($this->ticket)->create([
            'direction' => MessageDirection::OUT, 'origin' => MessageOrigin::PAINEL, 'author_user_id' => $this->author->id,
            'wa_message_id' => 'MINE1', 'body' => 'Texto original', 'sent_at' => now(), ...$attributes,
        ]);
    }

    private function edit(string $text, ?Message $message = null, ?User $actor = null): void
    {
        app(EditTicketMessage::class)($message ?? $this->mine, $actor ?? $this->author, $text);
    }

    private function revoke(?Message $message = null): void
    {
        app(RevokeTicketMessage::class)($message ?? $this->mine, $this->author);
    }

    private function echo(string $event, array $payload): WahaEvent
    {
        return WahaEvent::create([
            'waha_event_id' => 'evt_'.uniqid(), 'channel_id' => $this->channel->id, 'event' => $event, 'session' => 'main',
            'payload' => ['event' => $event, 'session' => 'main', 'payload' => $payload], 'received_at' => now(),
        ]);
    }

    private function refuses(callable $action): void
    {
        try {
            $action();
            $this->fail('devia recusar');
        } catch (MessageActionFailed) {
            Http::assertNothingSent();
        }
    }

    public function test_editar_chama_o_waha_grava_o_evento_e_nao_muda_o_corpo(): void
    {
        $this->edit('  Texto novo ');

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->url() === self::ROUTE && $r['text'] === 'Texto novo');
        $event = TicketEvent::query()->where('type', 'message_edited')->sole();
        $this->assertSame([$this->author->id, ['message_id' => $this->mine->id]], [$event->actor_user_id, $event->payload]);
        $this->assertSame('Texto original', $this->mine->refresh()->body, 'o corpo novo entra pelo eco');
        $this->assertSame(0, MessageEdit::count());
    }

    public function test_o_eco_da_edicao_grava_a_versao_anterior_e_o_texto_novo(): void
    {
        $this->assertTrue(app(ApplyMessageChange::class)->edited($this->echo('message.edited', [
            'id' => 'true_'.self::PEER.'_ED1', 'from' => self::PEER, 'fromMe' => true, 'body' => 'Texto novo', 'editedMessageId' => 'MINE1', 'timestamp' => now()->timestamp,
        ])));

        $this->assertSame('Texto novo', $this->mine->refresh()->body);
        $this->assertSame('Texto original', MessageEdit::query()->sole()->previous_body);
    }

    public function test_editar_aos_15_min_e_1_s_e_recusado_sem_chamar_o_waha(): void
    {
        $late = $this->own(['wa_message_id' => 'LATE1', 'sent_at' => now()->subSeconds(901)]);

        $this->refuses(fn () => $this->edit('x', $late));
    }

    public function test_texto_vazio_igual_ou_acima_do_limite_e_recusado(): void
    {
        foreach (['', '   ', 'Texto original', str_repeat('a', 4097)] as $text) {
            $this->refuses(fn () => $this->edit($text));
        }
    }

    public function test_editar_mensagem_de_outro_atendente_ou_do_cliente_e_recusado(): void
    {
        $other = User::factory()->create(['role' => UserRole::ADMIN]);
        $client = Message::factory()->for($this->ticket)->create(['wa_message_id' => 'CLI1']);

        $this->refuses(fn () => $this->edit('x', $this->mine, $other));
        $this->refuses(fn () => $this->edit('x', $client));
    }

    public function test_mensagem_sem_wa_message_id_nao_edita_nem_apaga(): void
    {
        $pending = $this->own(['wa_message_id' => null]);

        $this->refuses(fn () => $this->edit('x', $pending));
        $this->refuses(fn () => $this->revoke($pending));
    }

    public function test_apagar_chama_o_waha_e_grava_o_evento_sem_marcar_apagada(): void
    {
        $this->revoke();

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === self::ROUTE);
        $event = TicketEvent::query()->where('type', 'message_revoked_by_agent')->sole();
        $this->assertSame([$this->author->id, ['message_id' => $this->mine->id]], [$event->actor_user_id, $event->payload]);
        $this->assertNull($this->mine->refresh()->revoked_at, 'o WAHA responde ok mesmo quando só apagou no celular da empresa');
    }

    public function test_o_eco_message_revoked_marca_apagada_e_mantem_o_texto(): void
    {
        $this->assertTrue(app(ApplyMessageChange::class)->revoked($this->echo('message.revoked', [
            'revokedMessageId' => 'MINE1', 'after' => ['id' => 'true_'.self::PEER.'_RV1'],
        ])));

        $this->mine->refresh();
        $this->assertNotNull($this->mine->revoked_at);
        $this->assertSame('Texto original', $this->mine->body);
    }

    public function test_apagar_nao_tem_janela_de_tempo(): void
    {
        $old = $this->own(['wa_message_id' => 'OLD1', 'sent_at' => now()->subDays(5)]);

        $this->revoke($old);

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), 'OLD1'));
    }

    public function test_recusa_do_waha_nao_grava_evento_nem_muda_o_painel(): void
    {
        $this->wahaStatus = 400;

        foreach ([fn () => $this->edit('Texto novo'), fn () => $this->revoke()] as $action) {
            try {
                $action();
                $this->fail('devia recusar');
            } catch (MessageActionFailed $e) {
                $this->assertStringContainsString('recusou', $e->getMessage());
            }
        }

        $this->assertSame(0, TicketEvent::query()->whereIn('type', ['message_edited', 'message_revoked_by_agent'])->count());
        $this->assertNull($this->mine->refresh()->revoked_at);
    }
}
