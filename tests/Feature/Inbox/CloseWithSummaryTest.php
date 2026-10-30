<?php

namespace Tests\Feature\Inbox;

use App\Actions\Inbox\CloseTicket;
use App\Enums\ClosedReason;
use App\Enums\CloseOutcome;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class CloseWithSummaryTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

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
        return Livewire::actingAs($this->me)->test(Inbox::class)->set('ticketId', $this->ticket->id);
    }

    public function test_o_painel_pede_motivo_e_resumo_e_nao_encerra_sem_eles(): void
    {
        $this->inbox()->call('openPanel', 'close')->assertSee('Motivo do encerramento')
            ->call('close')->assertHasErrors(['closeOutcome', 'closeSummary'])
            ->set('closeOutcome', 'resolvido')->set('closeSummary', 'ok')->call('close')->assertHasErrors(['closeSummary'])
            ->set('closeOutcome', 'inventado')->set('closeSummary', 'Cotacao enviada')->call('close')->assertHasErrors(['closeOutcome']);

        $this->assertSame(TicketStatus::EM_ATENDIMENTO, $this->ticket->fresh()?->status);
    }

    public function test_resumo_acima_de_500_caracteres_e_recusado(): void
    {
        $this->inbox()->call('openPanel', 'close')->set('closeOutcome', 'resolvido')->set('closeSummary', str_repeat('a', 501))->call('close')->assertHasErrors(['closeSummary']);
    }

    public function test_encerra_gravando_motivo_resumo_e_o_evento(): void
    {
        $this->inbox()->call('openPanel', 'close')->set('closeOutcome', 'cliente_pediu')->set('closeSummary', '  Cliente vai aprovar com o socio.  ')->call('close')->assertHasNoErrors();

        $closed = $this->ticket->fresh();
        $this->assertSame(TicketStatus::ENCERRADO, $closed?->status);
        $this->assertSame(CloseOutcome::CLIENTE_PEDIU, $closed?->close_outcome);
        $this->assertSame('Cliente vai aprovar com o socio.', $closed?->close_summary);
        $this->assertSame(ClosedReason::MANUAL, $closed?->closed_reason);
        $this->assertSame(
            ['reason' => 'manual', 'outcome' => 'cliente_pediu', 'summary' => 'Cliente vai aprovar com o socio.'],
            $this->ticket->events()->where('type', 'closed')->sole()->payload,
        );
    }

    public function test_encerrar_duas_vezes_grava_um_evento_so_e_mantem_o_primeiro_resumo(): void
    {
        (new CloseTicket)($this->ticket, $this->me, ClosedReason::MANUAL, CloseOutcome::RESOLVIDO, 'Primeiro resumo');
        (new CloseTicket)($this->ticket->fresh() ?? $this->ticket, $this->me, ClosedReason::MANUAL, CloseOutcome::SEM_RESPOSTA, 'Segundo resumo');

        $this->assertSame(1, $this->ticket->events()->where('type', 'closed')->count());
        $this->assertSame('Primeiro resumo', $this->ticket->fresh()?->close_summary);
        $this->assertSame(CloseOutcome::RESOLVIDO, $this->ticket->fresh()?->close_outcome);
    }

    public function test_resumo_so_com_espacos_nao_conta_como_texto(): void
    {
        $this->inbox()->call('openPanel', 'close')->set('closeOutcome', 'resolvido')->set('closeSummary', '  ab')->call('close')->assertHasErrors(['closeSummary']);
    }

    public function test_quem_encerra_depois_de_outro_recebe_o_aviso_em_vez_de_erro(): void
    {
        $component = $this->inbox()->call('openPanel', 'close')->set('closeOutcome', 'resolvido')->set('closeSummary', 'Cotacao enviada');

        (new CloseTicket)($this->ticket, User::factory()->create(['role' => UserRole::SUPERVISOR]), ClosedReason::MANUAL, CloseOutcome::SEM_RESPOSTA, 'Primeiro resumo');

        $component->call('close')->assertSet('notice', 'Este ticket já foi encerrado por outra pessoa.')->assertSet('panel', null);
        $this->assertSame('Primeiro resumo', $this->ticket->fresh()?->close_summary);
    }

    public function test_quem_nao_ve_o_ticket_nao_abre_o_painel_de_encerramento(): void
    {
        $other = User::factory()->create(['role' => UserRole::ATENDENTE]);

        Livewire::actingAs($other)->test(Inbox::class)->set('ticketId', $this->ticket->id)->call('openPanel', 'close')->assertNotFound();
    }

    public function test_encerramento_sem_motivo_continua_valendo_para_a_rotina(): void
    {
        (new CloseTicket)($this->ticket, $this->me, ClosedReason::INATIVIDADE);

        $closed = $this->ticket->fresh();
        $this->assertNull($closed?->close_outcome);
        $this->assertNull($closed?->close_summary);
        $this->assertSame(['reason' => 'inatividade'], $this->ticket->events()->where('type', 'closed')->sole()->payload);
    }
}
