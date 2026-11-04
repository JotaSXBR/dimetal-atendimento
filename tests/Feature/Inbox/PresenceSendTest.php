<?php

namespace Tests\Feature\Inbox;

use App\Enums\DeliveryStatus;
use App\Enums\MessageOrigin;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Jobs\GoOfflineJob;
use App\Jobs\SendTicketMessageJob;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use App\Services\Inbox\Presence;
use App\Services\Inbox\SendTicketMessage;
use App\Services\Waha\WahaRequestException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use Tests\TestCase;

class PresenceSendTest extends TestCase
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

        $this->channel = Channel::factory()->connected()->create(['waha_session' => 'main']);
        $this->ticket = Ticket::factory()
            ->for(Contact::factory()->create(['wa_id' => self::PEER]))
            ->for($this->channel)
            ->create(['status' => TicketStatus::EM_ATENDIMENTO]);
        $this->author = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $this->ticket->update(['assigned_user_id' => $this->author->id]);
    }

    private function send(string $text = 'Segue o orcamento'): Message
    {
        return app(SendTicketMessage::class)($this->ticket, $this->author, $text);
    }

    public function test_sequencia_visto_digitando_envio(): void
    {
        Queue::fake();
        Message::factory()->for($this->ticket)->create(['wa_message_id' => 'IN1']);
        Message::factory()->for($this->ticket)->create(['wa_message_id' => 'IN2']);
        Http::fake(['*/api/sendText' => Http::response(['id' => 'true_x@c.us_OUT'], 201), '*' => Http::response([], 201)]);

        $this->send();

        Http::assertSentInOrder([
            fn (Request $r) => str_ends_with($r->url(), '/api/main/presence') && $r['presence'] === 'online',
            fn (Request $r) => str_ends_with($r->url(), '/api/sendSeen') && count($r['messageIds']) === 2
                && collect($r['messageIds'])->every(fn (string $id) => preg_match('/^false_'.preg_quote(self::PEER, '/').'_IN[12]$/', $id) === 1),
            fn (Request $r) => str_ends_with($r->url(), '/api/startTyping'),
            fn (Request $r) => str_ends_with($r->url(), '/api/stopTyping'),
            fn (Request $r) => str_ends_with($r->url(), '/api/sendText'),
        ]);
        Sleep::assertSleptTimes(1);
    }

    private function botSays(string $text): Message
    {
        $message = app(SendTicketMessage::class)->create($this->ticket, null, $text, MessageOrigin::BOT);
        app(SendTicketMessage::class)->deliver($message);

        return $message;
    }

    public function test_bot_manda_online_visto_e_digitando_antes_do_texto(): void
    {
        Queue::fake();
        Message::factory()->for($this->ticket)->create(['wa_message_id' => 'IN1']);
        Http::fake(['*/api/sendText' => Http::response(['id' => 'true_x@c.us_OUT'], 201), '*' => Http::response([], 201)]);

        $this->botSays('Olá!');

        Http::assertSentInOrder([
            fn (Request $r) => str_ends_with($r->url(), '/api/main/presence') && $r['presence'] === 'online',
            fn (Request $r) => str_ends_with($r->url(), '/api/sendSeen'),
            fn (Request $r) => str_ends_with($r->url(), '/api/startTyping'),
            fn (Request $r) => str_ends_with($r->url(), '/api/stopTyping'),
            fn (Request $r) => str_ends_with($r->url(), '/api/sendText'),
        ]);
        Sleep::assertSleptTimes(1);
    }

    public function test_bot_em_lote_manda_visto_so_na_primeira(): void
    {
        Queue::fake();
        Message::factory()->for($this->ticket)->create(['wa_message_id' => 'IN1']);
        Http::fake(['*/api/sendText' => Http::sequence()->push(['id' => 'true_x@c.us_1'], 201)->push(['id' => 'true_x@c.us_2'], 201), '*' => Http::response([], 201)]);

        $this->botSays('Olá!');
        $this->botSays('Escolha uma opção');

        $seen = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/api/sendSeen'));
        $typing = Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/api/startTyping'));
        $this->assertCount(1, $seen);
        $this->assertCount(2, $typing, 'digitando vale para cada mensagem do lote');
    }

    public function test_falha_no_visto_do_bot_nao_impede_o_envio(): void
    {
        Message::factory()->for($this->ticket)->create(['wa_message_id' => 'IN1']);
        Http::fake(['*/api/sendSeen' => Http::response('boom', 500), '*/api/sendText' => Http::response(['id' => 'true_x@c.us_OUT'], 201), '*' => Http::response([], 201)]);

        $message = $this->botSays('Olá!');

        $this->assertSame(DeliveryStatus::SENT, $message->fresh()?->delivery_status);
    }

    public function test_bot_nao_manda_typing_nem_paused_com_humano_ativo_na_conversa(): void
    {
        Queue::fake();
        Http::fake(['*/api/sendText' => Http::response(['id' => 'true_x@c.us_OUT'], 201), '*' => Http::response([], 201)]);
        app(Presence::class)->humanActive($this->channel, self::PEER);

        $this->botSays('Olá!');

        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/api/startTyping') || str_ends_with($r->url(), '/api/stopTyping'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/sendText'));
    }

    public function test_gravacao_do_atendente_pelo_livewire_segura_o_digitando_do_bot(): void
    {
        Queue::fake();
        Http::fake(['*/api/sendText' => Http::response(['id' => 'true_x@c.us_OUT'], 201), '*' => Http::response([], 201)]);

        Livewire::actingAs($this->author)->test(Inbox::class)->call('select', $this->ticket->id)->call('recording', true, $this->ticket->id);
        $this->botSays('Olá!');

        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/api/startTyping') || str_ends_with($r->url(), '/api/stopTyping'));
    }

    public function test_offline_pendente_do_bot_nao_derruba_a_gravacao_do_atendente(): void
    {
        Queue::fake();
        Http::fake(['*/api/sendText' => Http::response(['id' => 'true_x@c.us_OUT'], 201), '*' => Http::response([], 201)]);

        $this->botSays('Olá!');
        app(Presence::class)->humanActive($this->channel, self::PEER);

        $jobs = Queue::pushed(GoOfflineJob::class)->values();
        $this->assertCount(2, $jobs, 'o do bot e o que a gravação do atendente agendou');

        app()->call([$jobs[0], 'handle']);
        app()->call([$jobs[1], 'handle']);

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/presence') && $r['presence'] === 'offline');
        $this->assertCount(3, Queue::pushed(GoOfflineJob::class), 'com o humano ainda ativo, o offline é reagendado');
    }

    public function test_offline_sai_quando_nenhum_humano_esta_ativo(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response([], 201)]);
        app(Presence::class)->humanActive($this->channel, self::PEER);

        // Expira a marca "atendente ativo" como o TTL faria (o Redis do CI não obedece ao `travel`).
        Cache::forget('presence-human:'.$this->channel->id);
        app()->call([Queue::pushed(GoOfflineJob::class)->first(), 'handle']);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/main/presence') && $r['presence'] === 'offline');
    }

    public function test_envio_depois_de_digitar_de_verdade_nao_repete_a_espera_artificial(): void
    {
        Queue::fake();
        Http::fake(['*/api/sendText' => Http::response(['id' => 'true_x@c.us_OUT'], 201), '*' => Http::response([], 201)]);
        app(Presence::class)->humanActive($this->channel, self::PEER);

        $this->send();

        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/api/startTyping'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/stopTyping'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/sendText'));
        Sleep::assertNeverSlept();
    }

    public function test_visto_do_bot_tem_teto_de_3_segundos_e_o_do_atendente_o_padrao(): void
    {
        Message::factory()->for($this->ticket)->create(['wa_message_id' => 'IN1']);
        $timeouts = [];
        $sent = 0;
        Http::fake(function (Request $request, array $options) use (&$timeouts, &$sent) {
            if (str_ends_with($request->url(), '/api/sendSeen')) {
                $timeouts[] = $options['timeout'] ?? null;
            }

            return Http::response(['id' => 'true_x@c.us_OUT'.(str_ends_with($request->url(), '/api/sendText') ? ++$sent : '')], 201);
        });

        $this->botSays('Olá!');
        Message::factory()->for($this->ticket)->create(['wa_message_id' => 'IN2']); // o visto só sai para mensagem nova
        $this->send('Segue');

        $this->assertSame([3, 15], $timeouts);
    }

    public function test_bot_depois_do_bloco_esperar_nao_repete_o_digitando(): void
    {
        Queue::fake();
        Http::fake(['*/api/sendText' => Http::sequence()->push(['id' => 'true_x@c.us_1'], 201)->push(['id' => 'true_x@c.us_2'], 201), '*' => Http::response([], 201)]);
        app(Presence::class)->botTyped($this->ticket);

        $this->botSays('Pronto');

        Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/api/startTyping'));
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/sendText'));
        Sleep::assertNeverSlept();

        $this->botSays('Outra');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/startTyping'));
    }

    public function test_online_sai_uma_vez_por_janela_e_de_novo_depois_do_offline(): void
    {
        Queue::fake();
        Http::fake(['*/api/sendText' => Http::sequence()->push(['id' => 'true_x@c.us_1'], 201)->push(['id' => 'true_x@c.us_2'], 201)->push(['id' => 'true_x@c.us_3'], 201), '*' => Http::response([], 201)]);
        $online = fn () => Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/api/main/presence') && ($r['presence'] ?? null) === 'online')->count();

        $this->botSays('Olá!');
        $this->botSays('Escolha uma opção');
        $this->assertSame(1, $online(), 'a rajada pede online uma vez só');

        app()->call([Queue::pushed(GoOfflineJob::class)->last(), 'handle']);
        $this->botSays('Outra');

        $this->assertSame(2, $online(), 'depois do offline a janela fecha e o online volta');
    }

    public function test_visto_nao_repete_ids_ja_vistos(): void
    {
        Queue::fake();
        Message::factory()->for($this->ticket)->create(['wa_message_id' => 'IN1']);
        Http::fake(['*/api/sendText' => Http::sequence()->push(['id' => 'true_x@c.us_1'], 201)->push(['id' => 'true_x@c.us_2'], 201)->push(['id' => 'true_x@c.us_3'], 201), '*' => Http::response([], 201)]);
        $seen = fn () => Http::recorded(fn (Request $r) => str_ends_with($r->url(), '/api/sendSeen'));

        $this->send('um');
        $this->send('dois');
        $this->assertCount(1, $seen(), 'a segunda resposta não reenvia o que já foi visto');

        Message::factory()->for($this->ticket)->create(['wa_message_id' => 'IN2']);
        $this->send('tres');

        $this->assertCount(2, $seen());
        $this->assertSame(['false_'.self::PEER.'_IN2'], $seen()->last()[0]['messageIds']);
    }

    public function test_falha_no_digitando_nao_impede_o_envio(): void
    {
        Http::fake(['*/api/startTyping' => Http::response('boom', 500), '*/api/sendText' => Http::response(['id' => 'true_x@c.us_OUT'], 201), '*' => Http::response([], 201)]);

        $message = $this->send();

        $this->assertSame(DeliveryStatus::SENT, $message->fresh()?->delivery_status);
    }

    public function test_falha_no_visto_nao_impede_o_envio(): void
    {
        Message::factory()->for($this->ticket)->create(['wa_message_id' => 'IN1']);
        Http::fake(['*/api/sendSeen' => Http::response('boom', 500), '*/api/sendText' => Http::response(['id' => 'true_x@c.us_OUT'], 201), '*' => Http::response([], 201)]);

        $message = $this->send();

        $this->assertSame(DeliveryStatus::SENT, $message->fresh()?->delivery_status);
    }

    public function test_offline_so_dispara_do_ultimo_envio(): void
    {
        Queue::fake();
        Http::fake(['*/api/sendText' => Http::sequence()->push(['id' => 'true_x@c.us_1'], 201)->push(['id' => 'true_x@c.us_2'], 201), '*' => Http::response([], 201)]);

        $this->send('um');
        $this->send('dois');

        $jobs = Queue::pushed(GoOfflineJob::class)->values();
        $this->assertCount(2, $jobs);

        app()->call([$jobs[0], 'handle']);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/presence') && $r['presence'] === 'offline');

        app()->call([$jobs[1], 'handle']);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/main/presence') && $r['presence'] === 'offline');
    }

    public function test_erro_463_ou_475_marca_falha_com_motivo_proprio_e_nao_repete(): void
    {
        Log::spy();
        Http::fake(['*/api/sendText' => Http::response(['message' => '2 UNKNOWN: error 463 tc-token'], 500), '*' => Http::response([], 201)]);

        try {
            $this->send();
            $this->fail('Deveria relançar a falha do WAHA');
        } catch (WahaRequestException) {
            $message = Message::query()->sole();

            $this->assertSame(DeliveryStatus::FAILED, $message->delivery_status);
            $this->assertStringStartsWith('Limite do WhatsApp', (string) $message->failure_reason);
            Log::shouldHaveReceived('warning')->once();
            Http::assertSentCount(4);
            Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/restart') || (str_contains($r->url(), '/presence') && $r['presence'] !== 'online'));
        }
    }

    public function test_mensagem_a_sai_da_fila_antes_de_b(): void
    {
        Queue::fake();

        $screen = Livewire::actingAs($this->author)->test(Inbox::class)->call('select', $this->ticket->id);
        $screen->set('draft', 'a longa '.str_repeat('x', 300))->call('send');
        $screen->set('draft', 'b')->call('send');

        $ids = Queue::pushed(SendTicketMessageJob::class)->map(fn ($job) => $job->messageId)->values()->all();
        $this->assertSame(Message::query()->orderBy('id')->pluck('id')->all(), $ids);
        $this->assertSame('outbound', Queue::pushed(SendTicketMessageJob::class)->first()->queue);
    }
}
