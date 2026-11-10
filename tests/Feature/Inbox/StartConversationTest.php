<?php

namespace Tests\Feature\Inbox;

use App\Actions\Inbox\StartConversation;
use App\Enums\MessageDirection;
use App\Enums\MessageOrigin;
use App\Enums\SectorStatus;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Jobs\SendTicketMessageJob;
use App\Models\BotSession;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use App\Models\WahaEvent;
use App\Services\Inbox\ConversationNotStarted;
use App\Services\Inbox\IngestMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class StartConversationTest extends TestCase
{
    use RefreshDatabase;

    private const PEER = '5516999999999@c.us';

    private Channel $channel;

    private Contact $contact;

    private Sector $sector;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
        $this->fakeCheck(['numberExists' => true]);

        $this->channel = Channel::factory()->connected()->create(['waha_session' => 'main']);
        $this->contact = Contact::factory()->create(['wa_id' => self::PEER]);
        $this->sector = Sector::factory()->create();
        $this->agent = User::factory()->create(['role' => UserRole::ATENDENTE, 'sign_messages' => false]);
        $this->agent->sectors()->attach($this->sector);
    }

    /** @param  array<string, mixed>  $body */
    private function fakeCheck(array $body, int $status = 200): void
    {
        Http::swap(new HttpFactory);
        Http::fake(['*/api/contacts/check-exists*' => Http::response($body, $status)]);
    }

    /** @return array{ticket: Ticket, created: bool} */
    private function start(string $text = 'Olá, tudo bem?', ?Contact $contact = null, ?Channel $channel = null, ?Sector $sector = null, ?User $user = null): array
    {
        return app(StartConversation::class)($user ?? $this->agent, $contact ?? $this->contact, $channel ?? $this->channel, $sector ?? $this->sector, $text);
    }

    private function assertNothingCreated(): void
    {
        $this->assertSame(0, Ticket::query()->count());
        $this->assertSame(0, Message::query()->count());
        $this->assertSame(0, TicketEvent::query()->count());
        Queue::assertNotPushed(SendTicketMessageJob::class);
    }

    private function refuses(callable $attempt, string $messagePart): void
    {
        try {
            $attempt();
            $this->fail('A ação deveria recusar.');
        } catch (ConversationNotStarted $e) {
            $this->assertStringContainsString($messagePart, $e->getMessage());
        }

        $this->assertNothingCreated();
    }

    public function test_cria_ticket_em_atendimento_com_dono_setor_evento_e_mensagem(): void
    {
        $result = $this->start();
        $ticket = $result['ticket'];

        $this->assertTrue($result['created']);
        $this->assertSame(TicketStatus::EM_ATENDIMENTO, $ticket->status);
        $this->assertSame($this->agent->id, $ticket->assigned_user_id);
        $this->assertSame($this->sector->id, $ticket->sector_id);
        $this->assertNull($ticket->waiting_since);
        $this->assertNull($ticket->last_inbound_at);

        $event = TicketEvent::query()->where('type', 'opened_by_agent')->firstOrFail();
        $this->assertSame($ticket->id, $event->ticket_id);
        $this->assertSame($this->agent->id, $event->actor_user_id);

        $message = Message::query()->firstOrFail();
        $this->assertSame($ticket->id, $message->ticket_id);
        $this->assertSame(MessageDirection::OUT, $message->direction);
        $this->assertSame(MessageOrigin::PAINEL, $message->origin);
        $this->assertSame($this->agent->id, $message->author_user_id);
        $this->assertSame('Olá, tudo bem?', $message->body);

        Queue::assertPushed(SendTicketMessageJob::class);
        $this->assertSame(0, BotSession::query()->count());
    }

    public function test_reaproveita_o_ticket_aberto_sem_criar_nem_enviar(): void
    {
        $open = Ticket::factory()->create(['contact_id' => $this->contact->id, 'channel_id' => $this->channel->id, 'status' => TicketStatus::AGUARDANDO]);
        Http::fake();

        $result = $this->start();

        $this->assertFalse($result['created']);
        $this->assertSame($open->id, $result['ticket']->id);
        $this->assertSame(1, Ticket::query()->count());
        $this->assertSame(0, Message::query()->count());
        Http::assertNothingSent();
        Queue::assertNotPushed(SendTicketMessageJob::class);
    }

    public function test_numero_sem_whatsapp_nao_cria_nada(): void
    {
        $this->fakeCheck(['numberExists' => false]);

        $this->refuses(fn () => $this->start(), 'não tem WhatsApp');
    }

    public function test_waha_com_erro_nao_cria_nada(): void
    {
        $this->fakeCheck(['message' => 'boom'], 500);

        $this->refuses(fn () => $this->start(), 'Não foi possível confirmar o número');
    }

    public function test_canal_inativo_ou_fora_do_ar_nao_cria_nada(): void
    {
        $inactive = Channel::factory()->connected()->create(['active' => false, 'waha_session' => 'inativo']);
        $down = Channel::factory()->create(['waha_session' => 'fora-do-ar']);

        $this->refuses(fn () => $this->start(channel: $inactive), 'canal');
        $this->refuses(fn () => $this->start(channel: $down), 'canal');
    }

    public function test_contato_so_com_lid_nao_cria_nada(): void
    {
        $lidOnly = Contact::factory()->create(['wa_id' => null, 'lid' => '12345@lid']);

        $this->refuses(fn () => $this->start(contact: $lidOnly), 'telefone');
    }

    public function test_texto_vazio_ou_longo_demais_nao_cria_nada(): void
    {
        $this->refuses(fn () => $this->start('   '), 'primeira mensagem');
        $this->refuses(fn () => $this->start(str_repeat('a', 4097)), '4096');
    }

    public function test_setor_que_nao_recebe_ou_que_o_atendente_nao_cobre_nao_cria_nada(): void
    {
        $off = Sector::factory()->desativado()->create();
        $other = Sector::factory()->create();
        $this->agent->sectors()->attach($off);

        $this->refuses(fn () => $this->start(sector: $off), 'setor');
        $this->refuses(fn () => $this->start(sector: $other), 'setor');
    }

    public function test_setor_apagado_depois_do_guard_nao_cria_nada(): void
    {
        $stale = $this->staleSector();
        Sector::query()->whereKey($stale->id)->delete();

        $this->refuses(fn () => $this->start(sector: $stale, user: $this->supervisor()), 'Escolha um setor');
        $this->assertPaceNotSpent();
    }

    public function test_setor_desativado_depois_do_guard_nao_cria_nada(): void
    {
        $stale = $this->staleSector();
        Sector::query()->whereKey($stale->id)->update(['status' => SectorStatus::DESATIVADO]);

        $this->refuses(fn () => $this->start(sector: $stale, user: $this->supervisor()), 'Escolha um setor');
        $this->assertPaceNotSpent();
    }

    /** Supervisor: apagar o setor desfaz o vínculo do atendente, e o `guard` já recusaria; o supervisor só cai na releitura. */
    private function supervisor(): User
    {
        return User::factory()->create(['role' => UserRole::SUPERVISOR, 'sign_messages' => false]);
    }

    /** O modelo é carregado ainda recebendo; o teste muda o banco depois, como outra aba faria. */
    private function staleSector(): Sector
    {
        RateLimiter::clear('start-conversation:channel:'.$this->channel->id);
        RateLimiter::clear('start-conversation:contact:'.$this->contact->id);

        return Sector::factory()->create();
    }

    /** Recusar dentro da transação não pode gastar o ritmo do canal nem o do contato. */
    private function assertPaceNotSpent(): void
    {
        $this->assertFalse(RateLimiter::tooManyAttempts('start-conversation:channel:'.$this->channel->id, 1));
        $this->assertEquals(0, RateLimiter::attempts('start-conversation:contact:'.$this->contact->id));
    }

    public function test_supervisor_inicia_em_qualquer_setor_que_receba(): void
    {
        $supervisor = User::factory()->create(['role' => UserRole::SUPERVISOR, 'sign_messages' => false]);
        $other = Sector::factory()->create();

        $result = $this->start(sector: $other, user: $supervisor);

        $this->assertSame($other->id, $result['ticket']->sector_id);
    }

    public function test_ritmo_um_por_canal_a_cada_30_segundos(): void
    {
        $this->start();
        $second = Contact::factory()->create(['wa_id' => '5516988888888@c.us']);

        try {
            $this->start(contact: $second);
            $this->fail('Deveria pedir para aguardar.');
        } catch (ConversationNotStarted $e) {
            $this->assertStringContainsString('Aguarde', $e->getMessage());
        }

        $this->assertSame(1, Ticket::query()->count());
    }

    public function test_ritmo_quatro_por_hora_para_o_mesmo_contato(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->start();
            Ticket::query()->update(['status' => TicketStatus::ENCERRADO, 'closed_at' => now()]);
            RateLimiter::clear('start-conversation:channel:'.$this->channel->id);
        }

        try {
            $this->start();
            $this->fail('O quinto início na hora deveria recusar.');
        } catch (ConversationNotStarted $e) {
            $this->assertStringContainsString('Aguarde', $e->getMessage());
        }

        $this->assertSame(4, Ticket::query()->count());
    }

    public function test_duas_chamadas_seguidas_geram_um_ticket_so(): void
    {
        $first = $this->start();
        $second = $this->start('de novo');

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['ticket']->id, $second['ticket']->id);
        $this->assertSame(1, Ticket::query()->count());
    }

    public function test_resposta_do_cliente_cai_no_mesmo_ticket_e_comeca_o_sla(): void
    {
        $ticket = $this->start()['ticket'];

        $event = WahaEvent::create([
            'waha_event_id' => 'evt_reply',
            'channel_id' => $this->channel->id,
            'event' => 'message',
            'session' => 'main',
            'payload' => ['payload' => [
                'id' => 'false_'.self::PEER.'_REPLY1',
                'from' => self::PEER,
                'to' => '5516999990001@c.us',
                'fromMe' => false,
                'body' => 'Oi! Pode falar.',
                'timestamp' => now()->timestamp,
            ]],
            'received_at' => now(),
        ]);
        app(IngestMessage::class)($event);

        $this->assertSame(1, Ticket::query()->count());
        $this->assertSame($ticket->id, Message::query()->where('body', 'Oi! Pode falar.')->firstOrFail()->ticket_id);
        $this->assertNotNull($ticket->fresh()->waiting_since);
    }
}
