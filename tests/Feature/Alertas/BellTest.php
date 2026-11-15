<?php

namespace Tests\Feature\Alertas;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Alerts\Bell;
use App\Models\Channel;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\ChannelDown;
use App\Notifications\ChannelNumberChanged;
use App\Notifications\MessagesRecovered;
use App\Notifications\SlaBreached;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_mostra_a_recuperacao_de_mensagens_e_a_importacao(): void
    {
        $eu = User::factory()->create();
        $canal = Channel::factory()->create(['name' => 'Comercial']);
        $eu->notify(new MessagesRecovered($canal, 3, 1));
        $eu->notify(new MessagesRecovered($canal, 12, 4, 'imported'));

        $this->actingAs($eu);

        Livewire::test(Bell::class)
            ->assertSee('Mensagens recuperadas')
            ->assertSee('Comercial: 3 mensagens em 1 conversa')
            ->assertSee('Histórico importado')
            ->assertSee('Comercial: 12 mensagens em 4 conversas');
    }

    public function test_importacao_limitada_avisa_no_sino(): void
    {
        $eu = User::factory()->create();
        $canal = Channel::factory()->create(['name' => 'Comercial']);
        $eu->notify(new MessagesRecovered($canal, 15000, 900, 'imported', 2, 15000));

        $this->actingAs($eu);

        Livewire::test(Bell::class)->assertSee('limitada a 15.000 mensagens');
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

    public function test_alerta_de_troca_de_numero_aparece_no_sino(): void
    {
        $eu = User::factory()->create();
        $channel = Channel::factory()->create(['name' => 'Número 2']);
        $eu->notify(new ChannelNumberChanged($channel));

        $this->actingAs($eu);

        Livewire::test(Bell::class)->assertSee('Número do WhatsApp mudou')->assertSee('Número 2');
    }

    public function test_o_menu_mostra_o_sino(): void
    {
        $this->actingAs(User::factory()->create())->get(route('inbox'))->assertOk()->assertSee('Alertas');
    }

    public function test_clicar_no_alerta_de_sla_marca_como_lido_e_abre_o_ticket(): void
    {
        $eu = User::factory()->create(['role' => UserRole::ADMIN]);
        $ticket = Ticket::factory()->create(['protocol' => '2026-000777']);
        $eu->notify(new SlaBreached($ticket));
        $id = $eu->notifications()->firstOrFail()->id;

        $this->actingAs($eu);

        Livewire::test(Bell::class)->call('open', $id)->assertRedirect(route('inbox', ['ticket' => $ticket->id]));
        $this->assertNotNull($eu->notifications()->firstOrFail()->read_at);
    }

    public function test_alerta_sem_ticket_so_marca_como_lido(): void
    {
        $eu = User::factory()->create();
        $eu->notify(new ChannelDown(Channel::factory()->create()));
        $id = $eu->notifications()->firstOrFail()->id;

        $this->actingAs($eu);

        Livewire::test(Bell::class)->call('open', $id)->assertNoRedirect();
        $this->assertNotNull($eu->notifications()->firstOrFail()->read_at);
    }

    public function test_ticket_que_o_usuario_nao_ve_mais_nao_ganha_link(): void
    {
        $atendente = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $ticket = Ticket::factory()->create(['sector_id' => Sector::factory()->create()->id, 'status' => TicketStatus::EM_ATENDIMENTO]);
        $atendente->notify(new SlaBreached($ticket));
        $id = $atendente->notifications()->firstOrFail()->id;

        $this->actingAs($atendente);

        Livewire::test(Bell::class)->call('open', $id)->assertNoRedirect();
        $this->assertNotNull($atendente->notifications()->firstOrFail()->read_at);
    }

    public function test_abrir_alerta_de_outro_usuario_nao_marca_nada(): void
    {
        $dono = User::factory()->create();
        $outro = User::factory()->create(['role' => UserRole::ADMIN]);
        $dono->notify(new SlaBreached(Ticket::factory()->create()));
        $id = $dono->notifications()->firstOrFail()->id;

        $this->actingAs($outro);

        Livewire::test(Bell::class)->call('open', $id)->assertNoRedirect();
        $this->assertNull($dono->notifications()->firstOrFail()->read_at);
    }
}
