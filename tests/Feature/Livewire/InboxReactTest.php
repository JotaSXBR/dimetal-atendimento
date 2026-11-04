<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\TicketEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/** O menu da bolha: a barra com os seis emojis e o clique que reage. */
class InboxReactTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private Ticket $ticket;

    private Message $original;

    private int $wahaStatus = 200;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.waha.url' => 'http://waha.test:3000', 'services.waha.key' => 'test-key']);
        Http::fake(fn () => Http::response([], $this->wahaStatus));

        $this->me = User::factory()->create(['role' => UserRole::ATENDENTE]);
        RateLimiter::clear('message-action:'.$this->me->id);
        $this->ticket = Ticket::factory()->create([
            'channel_id' => Channel::factory()->connected()->create(['waha_session' => 'main'])->id,
            'contact_id' => Contact::factory()->create(['wa_id' => '5516997524950@c.us'])->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            'assigned_user_id' => $this->me->id,
        ]);
        $this->original = Message::factory()->for($this->ticket)->create(['wa_message_id' => 'ORIG1']);
    }

    private function inbox(): Testable
    {
        return Livewire::actingAs($this->me)->test(Inbox::class)->call('select', $this->ticket->id);
    }

    private function reactions(): int
    {
        return TicketEvent::query()->where('type', 'message_reacted')->count();
    }

    public function test_barra_mostra_os_seis_emojis_e_clicar_reage(): void
    {
        $component = $this->inbox();

        foreach (['👍', '❤️', '😂', '😮', '😢', '🙏'] as $emoji) {
            $component->assertSeeHtml("\$parent.react({$this->original->id}, '{$emoji}')");
        }

        $component->call('react', $this->original->id, '👍');

        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r['reaction'] === '👍');
        $this->assertSame(1, $this->reactions());
    }

    public function test_acima_do_teto_a_acao_e_recusada_com_aviso(): void
    {
        config(['security.message_action_per_minute' => 2]);
        $component = $this->inbox();

        foreach (['👍', '😂', '🙏'] as $emoji) {
            $component->call('react', $this->original->id, $emoji);
        }

        $component->assertSee('rápido demais');
        $this->assertSame(2, $this->reactions());
    }

    public function test_falha_do_waha_mostra_o_motivo_e_nao_grava_evento(): void
    {
        $this->wahaStatus = 400;

        $this->inbox()->call('react', $this->original->id, '👍')->assertSee('recusou');

        $this->assertSame(0, $this->reactions());
    }

    public function test_mensagem_de_outro_ticket_e_ignorada_e_emoji_invalido_e_recusado(): void
    {
        $other = Ticket::factory()->create([
            'channel_id' => $this->ticket->channel_id,
            'contact_id' => Contact::factory()->create()->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            'assigned_user_id' => $this->me->id,
        ]);
        $foreign = Message::factory()->for($other)->create(['wa_message_id' => 'OTHER1']);

        $this->inbox()->call('react', $foreign->id, '👍')->call('react', $this->original->id, '🔥')->assertSee('não permitido');

        Http::assertNothingSent();
    }

    public function test_sem_poder_responder_ninguem_reage(): void
    {
        $this->ticket->update(['status' => TicketStatus::ENCERRADO]);

        $this->inbox()->call('react', $this->original->id, '👍')->assertForbidden();
    }
}
