<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Jobs\GoOfflineJob;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class InboxTypingTest extends TestCase
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
        Http::fake(['*' => Http::response([], 201)]);

        $this->channel = Channel::factory()->connected()->create(['waha_session' => 'main']);
        $this->author = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $this->ticket = Ticket::factory()
            ->for(Contact::factory()->create(['wa_id' => self::PEER]))
            ->for($this->channel)
            ->create(['status' => TicketStatus::EM_ATENDIMENTO, 'assigned_user_id' => $this->author->id]);
    }

    private function screen(): Testable
    {
        return Livewire::actingAs($this->author)->test(Inbox::class)->call('select', $this->ticket->id);
    }

    public function test_digitar_manda_online_e_typing_ao_cliente(): void
    {
        $this->screen()->call('typing', true, $this->ticket->id);

        Http::assertSentInOrder([
            fn (Request $r) => str_ends_with($r->url(), '/api/main/presence') && $r['presence'] === 'online' && ! isset($r['chatId']),
            fn (Request $r) => str_ends_with($r->url(), '/api/main/presence') && $r['presence'] === 'typing' && $r['chatId'] === self::PEER,
        ]);
    }

    public function test_varios_avisos_seguidos_pedem_online_uma_vez_so(): void
    {
        $screen = $this->screen();

        $screen->call('typing', true, $this->ticket->id)->call('typing', true, $this->ticket->id)->call('typing', true, $this->ticket->id);

        $this->assertCount(1, Http::recorded(fn (Request $r) => ($r['presence'] ?? null) === 'online'));
        $this->assertCount(3, Http::recorded(fn (Request $r) => ($r['presence'] ?? null) === 'typing'));
    }

    public function test_parar_de_digitar_manda_paused_e_agenda_offline(): void
    {
        Queue::fake();

        $this->screen()->call('typing', false, $this->ticket->id);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/main/presence') && $r['presence'] === 'paused' && $r['chatId'] === self::PEER);
        Queue::assertPushed(GoOfflineJob::class);
    }

    public function test_limite_de_20_por_minuto_nao_devolve_erro(): void
    {
        $screen = $this->screen();

        for ($i = 0; $i < 25; $i++) {
            $screen->call('typing', true, $this->ticket->id)->assertHasNoErrors();
        }

        $typing = Http::recorded(fn (Request $r) => ($r['presence'] ?? null) === 'typing');
        $this->assertCount(20, $typing);
    }

    public function test_quem_nao_pode_responder_nao_manda_presenca(): void
    {
        $other = User::factory()->create(['role' => UserRole::ATENDENTE]);

        try {
            Livewire::actingAs($other)->test(Inbox::class)->call('typing', true, $this->ticket->id);
        } catch (\Throwable) {
            // 403/404: o que importa é nada ter saído para o WhatsApp.
        }

        Http::assertNothingSent();
    }
}
