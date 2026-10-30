<?php

namespace Tests\Feature\Alertas;

use App\Livewire\Alerts\Bell;
use App\Models\Channel;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\ChannelDown;
use App\Notifications\SlaBreached;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class BellTest extends TestCase
{
    use RefreshDatabase;

    public function test_lista_so_as_do_usuario_logado_e_conta_as_nao_lidas(): void
    {
        $eu = User::factory()->create();
        $outro = User::factory()->create();
        $ticket = Ticket::factory()->create(['protocol' => '2026-000142']);
        $eu->notify(new SlaBreached($ticket));
        $eu->notify(new ChannelDown($ticket->channel));
        $outro->notify(new SlaBreached($ticket));

        $this->actingAs($eu);

        Livewire::test(Bell::class)
            ->assertSee('SLA estourado')
            ->assertSee('2026-000142')
            ->assertSee('WhatsApp desconectado')
            ->assertViewHas('unread', 2);
    }

    public function test_marcar_como_lida_so_vale_para_a_propria(): void
    {
        $eu = User::factory()->create();
        $outro = User::factory()->create();
        $ticket = Ticket::factory()->create();
        $eu->notify(new SlaBreached($ticket));
        $outro->notify(new SlaBreached($ticket));
        $minha = $eu->notifications()->firstOrFail();
        $alheia = $outro->notifications()->firstOrFail();

        $this->actingAs($eu);
        $teste = Livewire::test(Bell::class);
        $teste->call('markRead', $alheia->id);
        $this->assertNull($alheia->fresh()?->read_at);

        $teste->call('markRead', $minha->id)->assertViewHas('unread', 0);
        $this->assertNotNull($minha->fresh()?->read_at);
    }

    public function test_marcar_todas_zera_o_contador(): void
    {
        $eu = User::factory()->create();
        $ticket = Ticket::factory()->create();
        $eu->notify(new SlaBreached($ticket));
        $eu->notify(new ChannelDown($ticket->channel));

        $this->actingAs($eu);

        Livewire::test(Bell::class)->call('markAllRead')->assertViewHas('unread', 0);
    }

    public function test_notificacao_com_mais_de_7_dias_nao_aparece(): void
    {
        $eu = User::factory()->create();
        $eu->notify(new SlaBreached(Ticket::factory()->create()));
        $eu->notifications()->update(['created_at' => now()->subDays(8)]);

        $this->actingAs($eu);

        Livewire::test(Bell::class)->assertDontSee('SLA estourado');
    }

    public function test_o_menu_mostra_o_sino(): void
    {
        $this->actingAs(User::factory()->create())->get(route('inbox'))->assertOk()->assertSee('Alertas');
    }
}
