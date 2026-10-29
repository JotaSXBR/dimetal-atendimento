<?php

namespace Tests\Feature\Inbox;

use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Enums\TicketStatus;
use App\Jobs\SendTicketMessageJob;
use App\Models\BusinessHour;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\WahaEvent;
use App\Services\Inbox\IngestMessage;
use App\Services\Inbox\SendTicketMessage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class AfterHoursReplyTest extends TestCase
{
    use RefreshDatabase;

    private const PEER = '5516997524950@c.us';

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
        Queue::fake();
        Sleep::fake();

        $this->channel = Channel::factory()->connected()->create(['waha_session' => 'main']);

        foreach ([1, 2, 3, 4, 5] as $weekday) {
            BusinessHour::create(['weekday' => $weekday, 'opens_at' => '08:00', 'closes_at' => '12:00']);
            BusinessHour::create(['weekday' => $weekday, 'opens_at' => '13:00', 'closes_at' => '18:00']);
        }

        BusinessHour::create(['weekday' => 6, 'opens_at' => '08:00', 'closes_at' => '12:00']);

        // Sexta-feira, 22h em São Paulo: fechado.
        $this->at('2026-10-30 22:00');
    }

    private function at(string $localTime): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($localTime, 'America/Sao_Paulo'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function event(array $payload): WahaEvent
    {
        return WahaEvent::create([
            'waha_event_id' => 'evt_'.uniqid(),
            'channel_id' => $this->channel->id,
            'event' => 'message',
            'session' => 'main',
            'payload' => ['event' => 'message', 'session' => 'main', 'payload' => $payload],
            'received_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function inbound(string $id = 'false_5516997524950@c.us_A1', array $extra = []): array
    {
        return array_merge([
            'id' => $id,
            'from' => self::PEER,
            'fromMe' => false,
            'body' => 'Quero um orcamento',
            'timestamp' => now()->timestamp,
            'hasMedia' => false,
            '_data' => ['Info' => ['PushName' => 'Maria']],
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function ingest(array $payload): ?Message
    {
        return app(IngestMessage::class)($this->event($payload));
    }

    private function botMessages(): int
    {
        return Message::query()->where('origin', MessageOrigin::BOT->value)->count();
    }

    public function test_fora_do_horario_cria_uma_resposta_automatica_com_as_variaveis_preenchidas(): void
    {
        $this->ingest($this->inbound());

        $reply = Message::query()->where('origin', MessageOrigin::BOT->value)->sole();
        $this->assertSame(MessageDirection::OUT, $reply->direction);
        $this->assertNull($reply->author_user_id);
        $this->assertStringContainsString('de segunda a sexta, das 8h às 12h e das 13h às 18h; sábado, das 8h às 12h', $reply->body);
        $this->assertStringContainsString('Voltamos amanhã às 8h.', $reply->body);
        $this->assertStringNotContainsString('{{', $reply->body);
        Queue::assertPushed(SendTicketMessageJob::class, fn (SendTicketMessageJob $job) => $job->messageId === $reply->id);
        $this->assertSame(1, $reply->ticket->events()->where('type', 'after_hours_reply')->count());
    }

    public function test_cinco_mensagens_seguidas_recebem_uma_unica_resposta(): void
    {
        foreach (range(1, 5) as $i) {
            $this->ingest($this->inbound("false_5516997524950@c.us_M{$i}"));
        }

        $this->assertSame(1, $this->botMessages());
    }

    public function test_dentro_do_horario_nao_responde(): void
    {
        $this->at('2026-10-30 10:00');

        $this->ingest($this->inbound());

        $this->assertSame(0, $this->botMessages());
    }

    public function test_resposta_desligada_nao_responde(): void
    {
        Setting::put('after_hours_enabled', false, 'boolean');

        $this->ingest($this->inbound());

        $this->assertSame(0, $this->botMessages());
    }

    public function test_texto_configurado_substitui_o_padrao(): void
    {
        Setting::put('after_hours_message', 'Fechados. Atendemos {{horario}}. Voltamos {{proximo_horario}}!');

        $this->ingest($this->inbound());

        $this->assertStringStartsWith('Fechados. Atendemos de segunda a sexta', Message::query()->where('origin', MessageOrigin::BOT->value)->sole()->body);
    }

    public function test_ticket_que_um_humano_ja_respondeu_nao_recebe_resposta_automatica(): void
    {
        Ticket::factory()->create([
            'contact_id' => Contact::factory()->create(['wa_id' => self::PEER]),
            'channel_id' => $this->channel->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            'first_human_reply_at' => now()->subHour(),
        ]);

        $this->ingest($this->inbound());

        $this->assertSame(0, $this->botMessages());
    }

    public function test_mensagem_antiga_do_historico_nao_dispara_resposta(): void
    {
        $this->ingest($this->inbound(extra: ['timestamp' => now()->subMinutes(10)->timestamp]));

        $this->assertSame(0, $this->botMessages());
    }

    public function test_mensagem_enviada_pelo_celular_nao_dispara_resposta(): void
    {
        $this->ingest($this->inbound('true_5516997524950@c.us_F1', ['fromMe' => true, 'from' => '5516999990001@c.us', 'to' => self::PEER]));

        $this->assertSame(0, $this->botMessages());
    }

    public function test_resposta_automatica_nao_conta_como_resposta_humana_nem_adia_o_encerramento(): void
    {
        Http::fake(['*' => Http::response(['id' => 'true_5516997524950@c.us_BOT1'], 201)]);
        $this->ingest($this->inbound());
        $reply = Message::query()->where('origin', MessageOrigin::BOT->value)->sole();

        app(SendTicketMessage::class)->deliver($reply);

        // Sem "visto" nem "digitando…": quem responde é um robô.
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'startTyping') || str_contains($request->url(), 'sendSeen'));
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/sendText'));

        $ticket = $reply->ticket->fresh();
        $this->assertNull($ticket?->first_human_reply_at);
        $this->assertNull($ticket?->last_outbound_at);

        $this->at('2026-11-01 22:00');
        $this->artisan('app:close-inactive-tickets')->assertSuccessful();

        $this->assertSame(TicketStatus::AGUARDANDO, $ticket?->fresh()?->status);
    }

    public function test_eco_da_resposta_automatica_e_adotado_e_nao_vira_fora_do_painel(): void
    {
        $this->ingest($this->inbound());
        $reply = Message::query()->where('origin', MessageOrigin::BOT->value)->sole();
        $reply->touch();

        $this->ingest($this->inbound('true_5516997524950@c.us_ECHO1', [
            'fromMe' => true,
            'from' => '5516999990001@c.us',
            'to' => self::PEER,
            'body' => $reply->body,
        ]));

        $this->assertSame(0, Message::query()->where('origin', MessageOrigin::FORA_DO_PAINEL->value)->count());
        $this->assertSame('ECHO1', $reply->fresh()?->wa_message_id);
        $this->assertNull($reply->ticket->fresh()?->first_human_reply_at);
    }
}
