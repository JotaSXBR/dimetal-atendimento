<?php

namespace Tests\Feature\Livewire;

use App\Enums\MessageDirection;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Movimento dos painéis da inbox: o teste fixa os atributos no HTML (o movimento em si se confere no navegador).
 * Ver docs/superpowers/specs/2026-11-14-animacao-paineis-e-compositor-design.md.
 */
class InboxMotionMarkupTest extends TestCase
{
    use RefreshDatabase;

    private const ENTRADA = 'x-transition:enter="transition duration-150 ease-[cubic-bezier(0.23,1,0.32,1)]"';

    private const SAIDA = 'x-transition:leave="transition duration-100 ease-out"';

    private User $me;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Storage::fake('local');

        $this->me = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $this->ticket = Ticket::factory()->create([
            'channel_id' => Channel::factory()->connected()->create()->id,
            'contact_id' => Contact::factory()->create()->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            'assigned_user_id' => $this->me->id,
        ]);
    }

    private function inbox(): Testable
    {
        return Livewire::actingAs($this->me)->test(Inbox::class)->call('select', $this->ticket->id);
    }

    private function mensagem(MessageDirection $direction): Message
    {
        return Message::factory()->for($this->ticket)->create(['direction' => $direction, 'wa_message_id' => strtoupper(bin2hex(random_bytes(6)))]);
    }

    /** O elemento (abertura da tag) que traz o atributo, para os asserts não pegarem o vizinho. */
    private function tagCom(string $html, string $marca): string
    {
        $at = strpos($html, $marca);
        $this->assertNotFalse($at, "falta {$marca}");

        return substr($html, strrpos(substr($html, 0, $at), '<') ?: 0, 1200);
    }

    public function test_o_menu_da_mensagem_tem_entrada_pelo_placed_e_saida_por_x_transition(): void
    {
        $this->mensagem(MessageDirection::IN);

        $menu = $this->tagCom($this->inbox()->html(), 'x-ref="menu"');

        $this->assertStringContainsString('x-bind:class="placed', $menu);
        $this->assertStringContainsString('duration-150 ease-[cubic-bezier(0.23,1,0.32,1)]', $menu);
        $this->assertStringContainsString(self::SAIDA, $menu);
    }
}
