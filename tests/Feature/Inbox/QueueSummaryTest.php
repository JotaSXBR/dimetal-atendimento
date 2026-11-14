<?php

namespace Tests\Feature\Inbox;

use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/** A Fila sem ticket aberto: resumo do dia no lugar do "Selecione um ticket." e sem a faixa vazia de detalhes. */
class QueueSummaryTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, mixed>  $override */
    private function ticket(string $protocol, array $override = []): Ticket
    {
        return Ticket::factory()->create(array_replace([
            'channel_id' => Channel::query()->firstOrCreate(['waha_session' => 'main'], ['name' => 'WhatsApp'])->id,
            'contact_id' => Contact::factory()->create()->id,
            'status' => TicketStatus::AGUARDANDO,
            'protocol' => $protocol,
            'waiting_since' => now()->subMinutes(20),
            'sla_due_at' => now()->addMinutes(30),
        ], $override));
    }

    private function page(?User $as = null): Testable
    {
        return Livewire::actingAs($as ?? User::factory()->create(['role' => UserRole::ADMIN]))->test(Inbox::class);
    }

    public function test_resumo_mostra_aguardando_meus_e_o_ticket_com_o_sla_mais_perto(): void
    {
        $admin = User::factory()->create(['role' => UserRole::ADMIN]);
        $near = $this->ticket('2026-000001', ['sla_due_at' => now()->addMinutes(5)]);
        $this->ticket('2026-000002', ['sla_due_at' => now()->addMinutes(40)]);
        $this->ticket('2026-000003', ['status' => TicketStatus::EM_ATENDIMENTO, 'assigned_user_id' => $admin->id, 'sla_due_at' => null, 'waiting_since' => null]);

        $html = $this->page($admin)->assertSeeHtml('data-testid="queue-summary"')->html();

        $this->assertMatchesRegularExpression('/data-testid="summary-waiting"[^>]*>\s*2\s*</', $html);
        $this->assertMatchesRegularExpression('/data-testid="summary-mine"[^>]*>\s*1\s*</', $html);
        $this->assertStringContainsString('wire:click="select('.$near->id.')"', $this->nearest($html));
        $this->assertStringContainsString('2026-000001', $this->nearest($html));
    }

    private function nearest(string $html): string
    {
        $this->assertSame(1, preg_match('/<button[^>]*data-testid="summary-nearest"[^>]*>.*?<\/button>/s', $html, $m), 'summary-nearest ausente');

        return $m[0];
    }

    public function test_o_ticket_vencido_e_o_mais_perto(): void
    {
        $this->ticket('2026-000001', ['sla_due_at' => now()->addMinutes(5)]);
        $overdue = $this->ticket('2026-000002', ['sla_due_at' => now()->subMinutes(10)]);

        $this->assertStringContainsString('wire:click="select('.$overdue->id.')"', $this->nearest($this->page()->html()));
    }

    public function test_ticket_de_setor_que_o_usuario_nao_ve_nao_vaza_no_resumo(): void
    {
        $vendas = Sector::factory()->create();
        $financeiro = Sector::factory()->create();
        $attendant = User::factory()->create(['role' => UserRole::ATENDENTE]);
        $attendant->sectors()->attach($vendas);

        $this->ticket('2026-000001', ['sector_id' => $vendas->id, 'sla_due_at' => now()->addMinutes(40)]);
        $this->ticket('2026-000099', ['sector_id' => $financeiro->id, 'sla_due_at' => now()->addMinutes(1)]);

        $html = $this->page($attendant)->html();

        $this->assertStringNotContainsString('2026-000099', $html);
        $this->assertStringContainsString('2026-000001', $this->nearest($html));
    }

    public function test_sem_ticket_com_sla_mostra_so_a_orientacao(): void
    {
        $this->ticket('2026-000001', ['sla_due_at' => null, 'waiting_since' => null]);

        $this->page()->assertDontSeeHtml('data-testid="summary-nearest"')->assertSee('Escolha uma conversa à esquerda.');
    }

    public function test_fila_vazia_mostra_zeros_e_a_orientacao(): void
    {
        $this->page()->assertSeeHtml('data-testid="queue-summary"')->assertDontSeeHtml('data-testid="summary-nearest"')->assertSee('Escolha uma conversa à esquerda.');
    }

    public function test_com_ticket_aberto_nao_ha_resumo(): void
    {
        $ticket = $this->ticket('2026-000001');

        $this->page()->call('select', $ticket->id)->assertDontSeeHtml('data-testid="queue-summary"');
    }

    public function test_sem_ticket_aberto_a_faixa_de_detalhes_nao_ocupa_coluna(): void
    {
        $ticket = $this->ticket('2026-000001');

        $this->assertStringContainsString('lg:grid-cols-[340px_minmax(0,1fr)]', $this->page()->html());
        $this->assertStringContainsString('var(--details-w', $this->page()->call('select', $ticket->id)->html());
    }
}
