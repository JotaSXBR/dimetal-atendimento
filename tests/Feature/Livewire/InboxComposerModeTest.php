<?php

namespace Tests\Feature\Livewire;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InboxComposerModeTest extends TestCase
{
    use RefreshDatabase;

    private ?Channel $channel = null;

    private function ticketFor(User $owner): Ticket
    {
        return Ticket::factory()->create(['channel_id' => ($this->channel ??= Channel::factory()->create())->id, 'status' => TicketStatus::EM_ATENDIMENTO, 'assigned_user_id' => $owner->id]);
    }

    public function test_rodape_tem_o_seletor_de_modo_com_rascunho_e_atalho(): void
    {
        $me = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $ticket = $this->ticketFor($me);

        Livewire::actingAs($me)->test(Inbox::class)->call('select', $ticket->id)
            ->assertSeeHtml('x-data="composerMode($wire, '.$ticket->id.', '.$me->id.')"')
            ->assertSeeHtml('wire:key="footer-'.$ticket->id.'"')
            ->assertSeeHtml('data-testid="mode-reply"')
            ->assertSeeHtml('data-testid="mode-note"')
            ->assertSee('Nota privada')
            ->assertSee('Só a equipe vê')
            ->assertSee('Salvar nota')
            ->assertSeeHtml('x-on:keydown.alt.p.window.prevent="toggle()"')
            ->assertSeeHtml('bg-warn-soft');
    }

    public function test_envio_passa_pelo_submit_do_alpine_e_o_digitando_so_vale_no_modo_resposta(): void
    {
        $me = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $ticket = $this->ticketFor($me);

        Livewire::actingAs($me)->test(Inbox::class)->call('select', $ticket->id)
            ->assertSeeHtml('x-on:submit.prevent="uploading === 0 && submit()"')
            ->assertDontSeeHtml('wire:submit="send"')
            ->assertSeeHtml("\$event.isTrusted && mode === 'reply'");
    }

    public function test_trocar_de_ticket_nao_leva_o_texto_do_campo_para_o_outro(): void
    {
        $me = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $a = $this->ticketFor($me);
        $b = $this->ticketFor($me);

        Livewire::actingAs($me)->test(Inbox::class)->call('select', $a->id)
            ->set('draft', 'texto do ticket A')
            ->call('select', $a->id)
            ->assertSet('draft', 'texto do ticket A')
            ->call('select', $b->id)
            ->assertSet('draft', '');
    }

    public function test_barra_do_seletor_nao_depende_de_variavel_do_campo_interno(): void
    {
        $me = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $ticket = $this->ticketFor($me);

        // A barra vive dentro do formulário do compositor (spec 4a, uma linha em coluna larga). `voiceBusy` é do escopo do campo e o
        // formulário some junto enquanto grava; por isso a barra em si não pode referenciá-lo (nem depender dele para aparecer).
        $html = Livewire::actingAs($me)->test(Inbox::class)->call('select', $ticket->id)->html();

        $this->assertSame(1, preg_match('/<div data-testid="composer-modes".*?<\/div>\s*<\/div>/s', $html, $m));
        $this->assertStringNotContainsString('voiceBusy', $m[0]);
        $this->assertStringContainsString('data-testid="mode-note"', $m[0]);
    }

    public function test_quem_nao_pode_responder_nao_ve_o_seletor(): void
    {
        $me = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $outro = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $ticket = Ticket::factory()->create(['channel_id' => Channel::factory()->create()->id, 'status' => TicketStatus::AGUARDANDO]);

        Livewire::actingAs($me)->test(Inbox::class)->call('select', $ticket->id)
            ->assertDontSeeHtml('data-testid="mode-note"');
        $this->assertNotNull($outro);
    }
}
