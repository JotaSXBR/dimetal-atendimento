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

    public function test_aviso_aparece_na_lista_e_na_conversa_e_nao_no_cabecalho(): void
    {
        $ticket = $this->ticket();
        $label = 'x-text="$store.clientPresence.label('.$ticket->id.')"';

        $html = Livewire::actingAs($this->me)->test(Inbox::class)->call('select', $ticket->id)->html();

        // A conversa é o container `flex-col-reverse`: tudo o que vem antes dele (lista e cabeçalho) só pode ter o aviso da linha da lista.
        $conversation = strpos($html, 'flex-col-reverse');
        $this->assertNotFalse($conversation);
        $this->assertSame(1, substr_count(substr($html, 0, $conversation), $label), 'só a linha da lista, nada no cabeçalho');
        $this->assertSame(1, substr_count(substr($html, $conversation), $label), 'a bolha do cliente dentro da conversa');
    }

    public function test_na_lista_o_aviso_toma_o_lugar_da_previa_e_a_ultima_mensagem_volta_ao_parar(): void
    {
        $ticket = $this->ticket();

        $html = Livewire::actingAs($this->me)->test(Inbox::class)->html();

        $this->assertSame(1, substr_count($html, 'x-show="! $store.clientPresence.label('.$ticket->id.')"'), 'a prévia só some enquanto há aviso');
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
