<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/** A tela recebe o estado "digitando/gravando" do Redis e o mostra na linha do ticket e no cabeçalho. */
class InboxClientPresenceTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->create(['role' => UserRole::ADMIN]);
        $this->channel = Channel::factory()->connected()->create();
    }

    private function ticket(?User $assignee = null): Ticket
    {
        return Ticket::factory()
            ->for(Contact::factory()->create(['wa_id' => '5516997524950@c.us']))
            ->for($this->channel)
            ->create(['status' => TicketStatus::EM_ATENDIMENTO, 'assigned_user_id' => ($assignee ?? $this->me)->id]);
    }

    public function test_render_entrega_os_estados_dos_tickets_da_lista(): void
    {
        $ticket = $this->ticket();
        Cache::put('client-presence:'.$ticket->id, 'digitando', 60);

        $html = Livewire::actingAs($this->me)->test(Inbox::class)->html();

        $this->assertStringContainsString('clientPresence.seed', $html);
        $this->assertStringContainsString('digitando', $html);
    }

    public function test_sem_estado_guardado_o_seed_vem_vazio(): void
    {
        $this->ticket();

        $html = Livewire::actingAs($this->me)->test(Inbox::class)->html();

        $this->assertStringContainsString('clientPresence.seed', $html);
        $this->assertStringNotContainsString('digitando', $html);
        $this->assertStringNotContainsString('gravando', $html);
    }

    public function test_linha_e_cabecalho_usam_o_mesmo_store(): void
    {
        $ticket = $this->ticket();

        $html = Livewire::actingAs($this->me)->test(Inbox::class)->call('select', $ticket->id)->html();

        $this->assertGreaterThanOrEqual(2, substr_count($html, '$store.clientPresence.label('.$ticket->id.')'), 'linha e cabeçalho');
    }

    public function test_ticket_que_o_usuario_nao_ve_nao_entra_no_seed(): void
    {
        $attendant = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $hidden = $this->ticket($this->me);
        Cache::put('client-presence:'.$hidden->id, 'gravando', 60);

        $html = Livewire::actingAs($attendant)->test(Inbox::class)->html();

        $this->assertStringNotContainsString('gravando', $html);
    }
}
