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
use App\Notifications\SlaWarning;
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

    /** Cria o alerta já com a hora dele, para a ordem da lista ser a do teste. */
    private function notifyAt(User $user, object $notification, string $at): void
    {
        $user->notify($notification);
        $user->notifications()->latest('id')->first()?->forceFill(['created_at' => $at])->save();
    }

    public function test_aviso_e_estouro_do_mesmo_ticket_viram_um_alerta_so(): void
    {
        $eu = User::factory()->create(['role' => UserRole::ADMIN]);
        $canal = Channel::factory()->create();
        $ticket = Ticket::factory()->create(['protocol' => '2026-000500', 'channel_id' => $canal->id]);
        $outro = Ticket::factory()->create(['protocol' => '2026-000501', 'channel_id' => $canal->id]);
        $this->notifyAt($eu, new SlaWarning($ticket), now()->subMinutes(10)->toDateTimeString());
        $this->notifyAt($eu, new SlaBreached($ticket), now()->subMinutes(5)->toDateTimeString());
        $this->notifyAt($eu, new SlaWarning($outro), now()->subMinutes(1)->toDateTimeString());

        $this->actingAs($eu);

        $screen = Livewire::test(Bell::class);

        $screen->assertSee('SLA estourado')->assertSee('2026-000501')->assertViewHas('unread', 2);
        $this->assertCount(2, $screen->viewData('items'));
        $this->assertSame(['SLA perto de estourar', 'SLA estourado'], array_column($screen->viewData('items'), 'title'));
    }

    public function test_ciclos_repetidos_do_mesmo_ticket_mostram_so_o_mais_recente(): void
    {
        $eu = User::factory()->create(['role' => UserRole::ADMIN]);
        $ticket = Ticket::factory()->create();
        foreach ([3, 2, 1] as $days) {
            $this->notifyAt($eu, new SlaBreached($ticket), now()->subDays($days)->toDateTimeString());
        }

        $this->actingAs($eu);

        $screen = Livewire::test(Bell::class);

        $this->assertCount(1, $screen->viewData('items'));
        $screen->assertViewHas('unread', 1);
    }

    public function test_marcar_um_sla_como_lido_limpa_os_do_mesmo_ticket(): void
    {
        $eu = User::factory()->create(['role' => UserRole::ADMIN]);
        $ticket = Ticket::factory()->create();
        $this->notifyAt($eu, new SlaWarning($ticket), now()->subMinutes(10)->toDateTimeString());
        $this->notifyAt($eu, new SlaBreached($ticket), now()->subMinutes(5)->toDateTimeString());
        $latest = $eu->notifications()->orderByDesc('created_at')->firstOrFail()->id;

        $this->actingAs($eu);

        Livewire::test(Bell::class)->call('markRead', $latest)->assertViewHas('unread', 0);
        $this->assertSame(0, $eu->unreadNotifications()->count());
    }

    public function test_alertas_que_nao_sao_de_sla_nao_sao_juntados(): void
    {
        $eu = User::factory()->create();
        $canal = Channel::factory()->create();
        $this->notifyAt($eu, new ChannelDown($canal), now()->subMinutes(10)->toDateTimeString());
        $this->notifyAt($eu, new ChannelDown($canal), now()->subMinutes(5)->toDateTimeString());

        $this->actingAs($eu);

        $this->assertCount(2, Livewire::test(Bell::class)->viewData('items'));
    }
}
