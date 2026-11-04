<?php

namespace Tests\Feature\Services;

use App\Models\Channel;
use App\Services\Inbox\MessageActionFailed;
use App\Services\Waha\WahaClient;
use App\Services\Waha\WahaMessageActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Reagir, editar e apagar no WAHA: o id completo da mensagem, o chat na rota e o motivo da recusa. */
class WahaMessageActionsTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = '5516997524950@c.us';

    private const ID = 'true_5516997524950@c.us_3EB0ABC';

    private WahaMessageActions $actions;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
        $this->actions = new WahaMessageActions(new WahaClient(Channel::factory()->create(['waha_session' => 'main'])));
    }

    public function test_reagir_manda_put_reaction_com_o_id_completo(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->actions->react(self::ID, '👍');

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && $r->url() === 'http://waha.test:3000/api/reaction'
            && $r['session'] === 'main' && $r['messageId'] === self::ID && $r['reaction'] === '👍');
    }

    public function test_emoji_vazio_remove_a_reacao(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->actions->react(self::ID, '');

        Http::assertSent(fn (Request $r) => $r['reaction'] === '');
    }

    public function test_editar_manda_put_na_rota_da_conversa_com_chat_e_id_codificados(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->actions->edit(self::CHAT, self::ID, 'Texto novo');

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && $r->url() === 'http://waha.test:3000/api/main/chats/5516997524950%40c.us/messages/true_5516997524950%40c.us_3EB0ABC'
            && $r['text'] === 'Texto novo');
    }

    public function test_apagar_manda_delete_na_mesma_rota(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $this->actions->revoke(self::CHAT, self::ID);

        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE'
            && $r->url() === 'http://waha.test:3000/api/main/chats/5516997524950%40c.us/messages/true_5516997524950%40c.us_3EB0ABC');
    }

    public function test_recusa_do_waha_vira_message_action_failed_com_motivo_em_portugues(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Message too old'], 400)]);

        try {
            $this->actions->edit(self::CHAT, self::ID, 'x');
            $this->fail('devia recusar');
        } catch (MessageActionFailed $e) {
            $this->assertStringContainsString('recusou', $e->getMessage());
        }
    }

    public function test_waha_fora_do_ar_vira_message_action_failed_sem_estourar(): void
    {
        Http::fake(fn () => throw new ConnectionException('timeout'));

        $this->expectException(MessageActionFailed::class);
        $this->expectExceptionMessage('não respondeu');

        $this->actions->react(self::ID, '👍');
    }

    public function test_mensagem_nao_encontrada_e_erro_do_servidor_tem_motivos_diferentes(): void
    {
        Http::fake(['*' => Http::sequence()->push([], 404)->push([], 500)]);

        $reasons = [];

        foreach ([1, 2] as $_) {
            try {
                $this->actions->revoke(self::CHAT, self::ID);
            } catch (MessageActionFailed $e) {
                $reasons[] = $e->getMessage();
            }
        }

        $this->assertCount(2, array_unique($reasons));
        $this->assertStringContainsString('não encontrada', $reasons[0]);
    }
}
