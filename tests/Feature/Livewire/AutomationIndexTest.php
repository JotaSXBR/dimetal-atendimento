<?php

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Automation\Index;
use App\Models\Channel;
use App\Models\Sector;
use App\Models\User;
use App\Models\Workflow;
use App\Services\Bot\WorkflowPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class AutomationIndexTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => UserRole::SUPERVISOR]);
    }

    private function page(): Testable
    {
        return Livewire::actingAs($this->user)->test(Index::class);
    }

    private function published(string $name = 'Fluxo A'): Workflow
    {
        $publisher = app(WorkflowPublisher::class);
        $workflow = $publisher->create($name, $this->user, template: true);
        $publisher->publish($workflow, $this->user);

        return $workflow;
    }

    public function test_so_supervisor_e_admin_entram(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::ATENDENTE]))->get('/automacao')->assertForbidden();
        $this->actingAs($this->user)->get('/automacao')->assertOk()->assertSee('Automação');
    }

    public function test_cria_workflow_vazio_e_do_modelo(): void
    {
        $this->page()->set('newName', 'Triagem')->set('template', 'em_branco')->call('create')->assertHasNoErrors();
        $this->page()->set('newName', 'Do modelo')->set('template', 'padrao')->call('create');

        $this->assertSame(['Do modelo', 'Triagem'], Workflow::query()->orderBy('name')->pluck('name')->all());
        $this->assertCount(5, Workflow::query()->where('name', 'Do modelo')->firstOrFail()->draft['nodes']);
        $this->assertCount(1, Workflow::query()->where('name', 'Triagem')->firstOrFail()->draft['nodes']);
    }

    public function test_a_galeria_agrupa_os_modelos_em_basicos_e_por_segmento(): void
    {
        $html = $this->page()->call('$set', 'showNew', true)->html();
        $positions = array_map(fn (string $text): int|false => strpos($html, $text), ['Básicos', 'Em branco', 'Atendimento padrão', 'Atendimento com horário', 'Por segmento', 'Distribuidora']);

        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions, 'grupos e modelos na ordem da galeria');
    }

    public function test_criar_de_modelo_com_setor_faltando_avisa_quais(): void
    {
        Sector::factory()->create(['name' => 'Vendas']);

        $this->page()->set('newName', 'Distribuição')->set('template', 'distribuidora')->call('create')
            ->assertHasNoErrors()
            ->assertSet('notice', 'Criado. Estes setores do modelo não existem aqui: Financeiro, SAC. Escolha o setor nos blocos Transferir.')
            ->assertSee('Estes setores do modelo não existem aqui');

        $this->assertSame(1, Workflow::query()->where('name', 'Distribuição')->count());
    }

    public function test_abrir_novo_workflow_limpa_o_aviso_da_criacao_anterior(): void
    {
        $this->page()->set('newName', 'Distribuição')->set('template', 'distribuidora')->call('create')
            ->assertSet('notice', 'Criado. Estes setores do modelo não existem aqui: Vendas, Financeiro, SAC. Escolha o setor nos blocos Transferir.')
            ->call('$set', 'showNew', true)
            ->assertSet('notice', null);
    }

    public function test_criar_com_todos_os_setores_nao_avisa(): void
    {
        foreach (['Vendas', 'Financeiro', 'SAC'] as $name) {
            Sector::factory()->create(['name' => $name]);
        }

        $this->page()->set('newName', 'Distribuição')->set('template', 'distribuidora')->call('create')->assertSet('notice', null);
    }

    public function test_novo_workflow_lista_os_modelos_e_cria_o_escolhido(): void
    {
        $page = $this->page()->call('$set', 'showNew', true)
            ->assertSet('template', 'padrao')
            ->assertSee('Em branco')
            ->assertSee('Atendimento padrão')
            ->assertSee('Atendimento com horário')
            ->assertSee('fora do expediente avisa o horário');

        $page->set('newName', 'Com horário')->set('template', 'horario')->call('create')->assertHasNoErrors();

        $this->assertCount(8, Workflow::query()->where('name', 'Com horário')->firstOrFail()->draft['nodes']);
    }

    public function test_card_mostra_resumo_do_rascunho(): void
    {
        $publisher = app(WorkflowPublisher::class);
        $publisher->create('Padrão', $this->user, template: 'padrao');
        $publisher->create('Com horário', $this->user, template: 'horario');
        Workflow::factory()->create(['name' => 'Fixos', 'draft' => ['nodes' => [
            ['id' => 'a', 'type' => 'transferir', 'x' => 0, 'y' => 0, 'data' => ['setor_id' => 3]],
            ['id' => 'b', 'type' => 'transferir', 'x' => 0, 'y' => 0, 'data' => ['setor_id' => 3]],
            ['id' => 'c', 'type' => 'transferir', 'x' => 0, 'y' => 0, 'data' => ['setor_id' => 4]],
        ], 'edges' => []]]);

        $this->page()
            ->assertSeeInOrder(['Com horário', '8 blocos', 'Fixos', '3 blocos', '2 setores', 'Padrão', '5 blocos']);
    }

    public function test_nome_e_obrigatorio(): void
    {
        $this->page()->set('newName', '  ')->call('create')->assertHasErrors(['newName' => 'required']);
    }

    public function test_duplica_e_apaga(): void
    {
        $workflow = Workflow::factory()->create(['name' => 'Original']);

        $this->page()->call('duplicate', $workflow->id)->call('delete', $workflow->id);

        $this->assertSame(['Original (cópia)'], Workflow::query()->pluck('name')->all());
    }

    public function test_ativar_e_desativar_por_canal_e_apagar_em_uso_mostra_erro(): void
    {
        $workflow = $this->published();
        $channel = Channel::factory()->create();

        $page = $this->page()->call('activate', $workflow->id, $channel->id);
        $this->assertSame($workflow->id, $channel->fresh()?->workflow_id);

        $page->call('delete', $workflow->id)->assertSee('Desative o workflow nos canais antes de apagar.');
        $this->assertNotNull(Workflow::query()->find($workflow->id));

        $page->call('deactivate', $channel->id);
        $this->assertNull($channel->fresh()?->workflow_id);
    }

    public function test_ativar_sem_versao_publicada_mostra_o_motivo(): void
    {
        $workflow = Workflow::factory()->create();
        $channel = Channel::factory()->create();

        $this->page()->call('activate', $workflow->id, $channel->id)->assertSee('Publique o workflow antes de ativá-lo em um canal.');

        $this->assertNull($channel->fresh()?->workflow_id);
    }

    public function test_lista_versoes_com_quem_publicou_e_restaura_no_rascunho(): void
    {
        $workflow = $this->published();
        $first = $workflow->latestVersion();
        app(WorkflowPublisher::class)->saveDraft($workflow, ['nodes' => [], 'edges' => []], $this->user);

        $page = $this->page()->call('toggle', $workflow->id)->assertSee('Versão 1')->assertSee($this->user->name);
        $page->call('restore', $workflow->id, $first?->id);

        $this->assertCount(5, $workflow->fresh()?->draft['nodes']);
    }
}
