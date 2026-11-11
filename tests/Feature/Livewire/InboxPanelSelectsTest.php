<?php

namespace Tests\Feature\Livewire;

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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Os selects dos painéis Transferir, Encerrar e Reatribuir precisam mostrar uma opção vazia de verdade: sem ela o navegador
 * exibe a primeira opção (por exemplo "Vendas") sem que o Livewire tenha valor, e o clique responde "Escolha o setor".
 */
class InboxPanelSelectsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => UserRole::ADMIN]);
        Sector::factory()->create(['name' => 'Vendas', 'menu_order' => 1]);
        Sector::factory()->create(['name' => 'Suporte', 'menu_order' => 2]);
        $this->ticket = Ticket::factory()->create([
            'channel_id' => Channel::factory()->connected()->create()->id,
            'contact_id' => Contact::factory()->create()->id,
            'status' => TicketStatus::EM_ATENDIMENTO,
            'assigned_user_id' => $this->admin->id,
            'sector_id' => null,
        ]);
    }

    private function open(string $panel): Testable
    {
        return Livewire::actingAs($this->admin)->test(Inbox::class)->set('ticketId', $this->ticket->id)->call('openPanel', $panel);
    }

    /** @return array<string, array{string, string}> */
    public static function panels(): array
    {
        return [
            'transferir' => ['transfer', 'Escolha o setor'],
            'encerrar' => ['close', 'Escolha o motivo'],
            'reatribuir' => ['reassign', 'Escolha a pessoa'],
        ];
    }

    #[DataProvider('panels')]
    public function test_o_select_do_painel_tem_opcao_vazia_visivel(string $panel, string $label): void
    {
        // Não pode ser `disabled`: o navegador pula a opção desabilitada e mostra a primeira de verdade, sem valor no Livewire.
        $this->assertMatchesRegularExpression('/<option(?![^>]*disabled)[^>]*value=""[^>]*>'.$label.'<\/option>/', $this->open($panel)->html());
    }

    public function test_transferir_sem_escolher_recusa_e_o_erro_aparece_uma_vez(): void
    {
        $html = $this->open('transfer')->call('transfer')->assertHasErrors('transferSectorId')->html();

        $this->assertSame(1, substr_count($html, 'Escolha o setor de destino.'));
        $this->assertSame(TicketStatus::EM_ATENDIMENTO, $this->ticket->fresh()?->status);
    }

    public function test_transferir_escolhendo_o_primeiro_setor_funciona(): void
    {
        $vendas = Sector::query()->where('name', 'Vendas')->firstOrFail();

        $this->open('transfer')->set('transferSectorId', $vendas->id)->call('transfer')->assertHasNoErrors();

        $this->assertSame($vendas->id, $this->ticket->fresh()?->sector_id);
        $this->assertSame(TicketStatus::AGUARDANDO, $this->ticket->fresh()?->status);
    }
}
