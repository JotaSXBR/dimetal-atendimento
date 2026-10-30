<?php

namespace Tests\Feature\History;

use App\Enums\CloseOutcome;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Inbox\Inbox;
use App\Models\Channel;
use App\Models\Contact;
use App\Models\Message;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InboxClosedTabTest extends TestCase
{
    use RefreshDatabase;

    private Channel $channel;

    private Sector $mine;

    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->channel = Channel::factory()->create();
        $this->mine = Sector::factory()->create();
        $this->agent = User::factory()->create(['role' => UserRole::ATENDENTE, 'sign_messages' => false]);
        $this->agent->sectors()->attach($this->mine);
    }

    private function closed(string $name, array $override = []): Ticket
    {
        return Ticket::factory()->create(array_replace([
            'channel_id' => $this->channel->id,
            'contact_id' => Contact::factory()->create(['name' => $name])->id,
            'status' => TicketStatus::ENCERRADO,
            'closed_at' => now()->subHour(),
            'sector_id' => $this->mine->id,
        ], $override));
    }

    private function inbox(?User $user = null): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::actingAs($user ?? $this->agent)->test(Inbox::class);
    }

    public function test_aba_lista_so_os_ultimos_7_dias_e_so_os_visiveis(): void
    {
        $this->closed('Recente Seis', ['closed_at' => now()->subDays(6)]);
        $this->closed('Antigo Oito', ['closed_at' => now()->subDays(8)]);
        $this->closed('Outro Setor', ['sector_id' => Sector::factory()->create()->id]);

        $this->inbox()->set('tab', 'encerrados')
            ->assertSee('Recente Seis')
            ->assertDontSee('Antigo Oito')
            ->assertDontSee('Outro Setor');
    }

    public function test_a_aba_limita_a_50_linhas_e_mostra_o_mais_recente_primeiro(): void
    {
        foreach (range(1, 51) as $i) {
            $this->closed('Contato '.$i, ['closed_at' => now()->subMinutes($i)]);
        }

        $closed = $this->inbox()->set('tab', 'encerrados')->assertSee('Contato 1')->assertDontSee('Contato 51')->viewData('closed');

        $this->assertCount(50, $closed);
    }

    public function test_as_outras_abas_nao_mostram_encerrados(): void
    {
        $this->closed('Encerrado Fantasma');

        $this->inbox()->set('tab', 'todos')->assertDontSee('Encerrado Fantasma');
    }

    public function test_selecionar_encerrado_abre_a_conversa_sem_compositor_e_mostra_motivo_e_resumo(): void
    {
        $ticket = $this->closed('Joaquim', ['close_outcome' => CloseOutcome::RESOLVIDO, 'close_summary' => 'Cotacao aprovada pelo socio']);
        Message::factory()->for($ticket)->create(['body' => 'Pode fechar o pedido']);

        $this->inbox()->set('tab', 'encerrados')->call('select', $ticket->id)
            ->assertSee('Pode fechar o pedido')
            ->assertSee('Resolvido')
            ->assertSee('Cotacao aprovada pelo socio')
            ->assertViewHas('canReply', false)
            ->assertViewHas('canClose', false);
    }

    public function test_acoes_continuam_negadas_em_encerrado(): void
    {
        $ticket = $this->closed('Joaquim', ['assigned_user_id' => $this->agent->id, 'closed_at' => now()->subHour()]);

        $component = $this->inbox()->call('select', $ticket->id)->call('claim')->assertSet('notice', 'Outra pessoa assumiu este ticket antes de você.');
        $component->set('draft', 'oi')->call('send')->assertForbidden();

        $fresh = $ticket->fresh();
        $this->assertSame(TicketStatus::ENCERRADO, $fresh?->status);
        $this->assertSame(0, Message::query()->count());
    }

    public function test_atendente_nao_seleciona_encerrado_de_outro_setor(): void
    {
        $foreign = $this->closed('Outro Setor', ['sector_id' => Sector::factory()->create()->id]);

        $this->inbox()->call('select', $foreign->id)->assertForbidden();
    }

    public function test_aba_tem_link_para_a_busca_completa(): void
    {
        $this->inbox()->set('tab', 'encerrados')->assertSee('Busca completa')->assertSeeHtml('href="'.url('/historico').'"');
    }
}
