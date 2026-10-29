<?php

namespace Tests\Feature\Inbox;

use App\Enums\DeliveryStatus;
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
use App\Services\Inbox\SendTicketMessage;
use App\Services\Waha\WahaRequestException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
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
            fn (Request $r) => str_ends_with($r->url(), '/api/sendSeen') && count($r['messageIds']) === 2
                && collect($r['messageIds'])->every(fn (string $id) => preg_match('/^false_'.preg_quote(self::PEER, '/').'_IN[12]$/', $id) === 1),
            fn (Request $r) => str_ends_with($r->url(), '/api/startTyping'),
            fn (Request $r) => str_ends_with($r->url(), '/api/stopTyping'),
            fn (Request $r) => str_ends_with($r->url(), '/api/sendText'),
        ]);
        Sleep::assertSleptTimes(1);
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
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/presence'));

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
            Http::assertSentCount(3);
            Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/restart') || str_contains($r->url(), '/presence'));
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
