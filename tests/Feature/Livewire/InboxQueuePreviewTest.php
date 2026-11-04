<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class InboxQueuePreviewTest extends TestCase
{
    use RefreshDatabase;

    private function screenWithLastMessage(string $body): Testable
    {
        $ticket = Ticket::factory()
            ->for(Contact::factory()->create(['wa_id' => '5516997524950@c.us']))
            ->for(Channel::factory()->connected()->create())
            ->create(['status' => TicketStatus::AGUARDANDO]);
        Message::factory()->for($ticket)->create(['body' => $body]);

        return Livewire::actingAs(User::factory()->create(['role' => UserRole::SUPERVISOR]))->test(Inbox::class);
    }

    public function test_previa_da_fila_mostra_negrito_e_italico(): void
    {
        $this->screenWithLastMessage('*oi* _tudo_')
            ->assertSeeHtml('<strong>oi</strong>')
            ->assertSeeHtml('<em>tudo</em>');
    }

    public function test_previa_escapa_html(): void
    {
        $this->screenWithLastMessage('<script>alert(1)</script>')
            ->assertDontSeeHtml('<script>alert(1)</script>')
            ->assertSeeHtml('&lt;script&gt;alert(1)&lt;/script&gt;');
    }

    public function test_asterisco_sem_fechar_fica_literal(): void
    {
        $this->screenWithLastMessage('*oi sem fechar')
            ->assertSee('*oi sem fechar')
            ->assertDontSeeHtml('<strong>');
    }
}
