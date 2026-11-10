<?php

namespace Tests\Feature\Livewire;

use App\Enums\SectorStatus;
use App\Enums\TicketStatus;
use App\Enums\UserRole;
use App\Livewire\Settings\Sectors;
use App\Models\QuickReply;
use App\Models\Sector;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class SectorsCrudTest extends TestCase
{
    use RefreshDatabase;

    private function page(): Testable
    {
        return Livewire::actingAs(User::factory()->create(['role' => UserRole::ADMIN]))->test(Sectors::class);
    }

    /** @return array<string, mixed> */
    private function graphTransferringTo(int $sectorId): array
    {
        return [
            'nodes' => [
                ['id' => 'ini', 'type' => 'inicio', 'x' => 0, 'y' => 0, 'data' => []],
                ['id' => 'tr', 'type' => 'transferir', 'x' => 0, 'y' => 0, 'data' => ['setor_id' => $sectorId]],
            ],
            'edges' => [['from' => 'ini', 'port' => 'out', 'to' => 'tr']],
        ];
    }

    public function test_admin_cria_setor_no_fim_da_ordem_e_ativo(): void
    {
        Sector::factory()->create(['menu_order' => 1]);
        Sector::factory()->create(['menu_order' => 2]);

        $this->page()->call('create', '  Logística ')->assertHasNoErrors();

        $new = Sector::query()->where('name', 'Logística')->firstOrFail();
        $this->assertSame(3, $new->menu_order);
        $this->assertSame(SectorStatus::ATIVO, $new->status);
    }

    public function test_nome_vazio_longo_ou_repetido_e_recusado_na_criacao(): void
    {
        Sector::factory()->create(['name' => 'Vendas']);

        $this->page()->call('create', '   ')->assertHasErrors('newName');
        $this->page()->call('create', str_repeat('a', 61))->assertHasErrors('newName');
        $this->page()->call('create', 'Vendas')->assertHasErrors('newName');

        $this->assertSame(1, Sector::query()->count());
    }

    public function test_renomear_mantem_vinculos_e_tickets(): void
    {
        $sector = Sector::factory()->create(['name' => 'Antigo']);
        $user = User::factory()->create();
        $user->sectors()->attach($sector->id);
        $ticket = Ticket::factory()->create(['sector_id' => $sector->id]);

        $this->page()->call('rename', $sector->id, 'Novo nome')->assertHasNoErrors();

        $this->assertSame('Novo nome', $sector->fresh()?->name);
        $this->assertSame($sector->id, $ticket->fresh()?->sector_id);
        $this->assertSame(1, $user->sectors()->count());
    }

    public function test_renomear_com_nome_de_outro_setor_ou_vazio_e_recusado_mas_o_proprio_nome_passa(): void
    {
        $a = Sector::factory()->create(['name' => 'Alfa']);
        Sector::factory()->create(['name' => 'Beta']);

        $this->page()->call('rename', $a->id, 'Beta')->assertHasErrors('rename.'.$a->id);
        $this->page()->call('rename', $a->id, '')->assertHasErrors('rename.'.$a->id);
        $this->page()->call('rename', $a->id, 'Alfa')->assertHasNoErrors();

        $this->assertSame('Alfa', $a->fresh()?->name);
    }

    public function test_apagar_sem_uso_funciona_e_solta_os_usuarios(): void
    {
        $sector = Sector::factory()->create();
        $user = User::factory()->create();
        $user->sectors()->attach($sector->id);

        $this->page()->call('remove', $sector->id)->assertHasNoErrors();

        $this->assertSoftDeleted($sector);
        $this->assertSame(0, $user->sectors()->count());
    }

    public function test_apagar_com_ticket_aberto_e_recusado_mas_encerrado_nao_impede(): void
    {
        $sector = Sector::factory()->create();
        Ticket::factory()->create(['sector_id' => $sector->id, 'status' => TicketStatus::AGUARDANDO]);

        $this->page()->call('remove', $sector->id)->assertHasErrors('remove.'.$sector->id);
        $this->assertNotSoftDeleted($sector);

        Ticket::query()->update(['status' => TicketStatus::ENCERRADO->value, 'closed_at' => now()]);

        $this->page()->call('remove', $sector->id)->assertHasNoErrors();
        $this->assertSoftDeleted($sector);
    }

    public function test_apagar_com_no_transferir_no_rascunho_e_recusado(): void
    {
        $sector = Sector::factory()->create();
        Workflow::factory()->create(['name' => 'Fluxo A', 'draft' => $this->graphTransferringTo($sector->id)]);

        $this->page()->call('remove', $sector->id)->assertHasErrors('remove.'.$sector->id);

        $this->assertNotSoftDeleted($sector);
    }

    public function test_apagar_com_no_transferir_na_ultima_versao_publicada_e_recusado(): void
    {
        $sector = Sector::factory()->create();
        $workflow = Workflow::factory()->create(['name' => 'Fluxo B']);
        WorkflowVersion::factory()->create(['workflow_id' => $workflow->id, 'number' => 1, 'graph' => $this->graphTransferringTo($sector->id)]);

        $this->page()->call('remove', $sector->id)->assertHasErrors('remove.'.$sector->id);

        $this->assertNotSoftDeleted($sector);
    }

    public function test_workflow_que_aponta_para_outro_setor_nao_impede(): void
    {
        $sector = Sector::factory()->create();
        $other = Sector::factory()->create();
        Workflow::factory()->create(['draft' => $this->graphTransferringTo($other->id)]);

        $this->page()->call('remove', $sector->id)->assertHasNoErrors();

        $this->assertSoftDeleted($sector);
    }

    public function test_apagar_com_resposta_rapida_do_setor_e_recusado(): void
    {
        $sector = Sector::factory()->create();
        QuickReply::query()->create(['shortcut' => 'oi', 'body' => 'Olá', 'sector_id' => $sector->id]);

        $this->page()->call('remove', $sector->id)->assertHasErrors('remove.'.$sector->id);

        $this->assertNotSoftDeleted($sector);
        $this->assertSame(1, QuickReply::query()->count());
    }

    public function test_nao_admin_nao_cria_nem_renomeia_nem_apaga(): void
    {
        $sector = Sector::factory()->create(['name' => 'Fixo']);

        foreach ([UserRole::SUPERVISOR, UserRole::ATENDENTE] as $role) {
            Livewire::actingAs(User::factory()->create(['role' => $role]))->test(Sectors::class)->assertForbidden();
        }

        $this->assertSame('Fixo', $sector->fresh()?->name);
        $this->assertNotSoftDeleted($sector);
        $this->assertSame(1, Sector::query()->count());
    }

    public function test_tela_mostra_os_campos_de_criar_renomear_e_apagar(): void
    {
        Sector::factory()->create(['name' => 'Vendas']);

        $this->page()
            ->assertSee('Novo setor')
            ->assertSee('Renomear Vendas')
            ->assertSee('Apagar Vendas');
    }

    public function test_corrida_ao_criar_com_o_mesmo_nome_mostra_a_mensagem_e_nao_estoura(): void
    {
        // Entre a validação e o insert, outro admin cria o mesmo nome: o índice único barra e a tela explica.
        Sector::creating(function (Sector $sector): void {
            if ($sector->name === 'Logística') {
                DB::table('sectors')->insert(['name' => 'Logística', 'menu_order' => 9, 'status' => 'ativo', 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $this->page()->call('create', 'Logística')->assertHasErrors('newName')->assertSee('Já existe um setor com esse nome.');
    }

    public function test_corrida_ao_renomear_para_um_nome_que_acabou_de_existir_mostra_a_mensagem(): void
    {
        $sector = Sector::factory()->create(['name' => 'Antigo']);
        Sector::updating(function (Sector $changing): void {
            if ($changing->name === 'Novo') {
                DB::table('sectors')->insert(['name' => 'Novo', 'menu_order' => 9, 'status' => 'ativo', 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $this->page()->call('rename', $sector->id, 'Novo')->assertHasErrors('rename.'.$sector->id);

        $this->assertSame('Antigo', $sector->fresh()?->name);
    }

    public function test_apagar_trava_a_linha_do_setor_antes_de_conferir_o_uso(): void
    {
        $sector = Sector::factory()->create();
        DB::enableQueryLog();

        $this->page()->call('remove', $sector->id)->assertHasNoErrors();

        $queries = array_column(DB::getQueryLog(), 'query');
        $lock = array_search(true, array_map(fn (string $q): bool => str_contains(strtolower($q), 'from "sectors"') && str_contains(strtolower($q), 'for update'), $queries), true);
        $check = array_search(true, array_map(fn (string $q): bool => str_contains(strtolower($q), 'from "tickets"'), $queries), true);

        $this->assertNotFalse($lock, 'O setor deveria ser lido com FOR UPDATE.');
        $this->assertNotFalse($check);
        $this->assertLessThan($check, $lock, 'A trava vem antes de conferir os tickets abertos.');
    }
}
