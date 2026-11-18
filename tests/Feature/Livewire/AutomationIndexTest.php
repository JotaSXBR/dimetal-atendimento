<?php

namespace Tests\Feature\Livewire;

use App\Enums\SectorStatus;
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

    /** A Automação é uma página da barra lateral, não do hub de Configuração: sem a navegação de Configuração ao lado. */
    public function test_a_pagina_nao_traz_a_navegacao_de_configuracao_mas_traz_o_titulo_e_o_botao(): void
    {
        $html = (string) $this->actingAs($this->user)->get('/automacao')->assertOk()->getContent();

        $this->assertStringNotContainsString('<nav aria-label="Configuração"', $html);
        $this->assertStringNotContainsString('data-test="settings-group-title"', $html);
        $this->assertStringContainsString('Novo workflow', $html);
        $this->assertStringContainsString('Workflows do pré-atendimento', $html);
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
        $this->assertCount(8, Workflow::query()->where('name', 'Do modelo')->firstOrFail()->draft['nodes']);
        $this->assertCount(1, Workflow::query()->where('name', 'Triagem')->firstOrFail()->draft['nodes']);
    }

    public function test_a_galeria_agrupa_os_modelos_em_basicos_e_por_segmento(): void
    {
        $html = $this->page()->call('$set', 'showNew', true)->html();
        $positions = array_map(fn (string $text): int|false => strpos($html, $text), ['Básicos', 'Em branco', 'Atendimento padrão', 'Por segmento', 'Distribuidora']);

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
            ->assertDontSee('Atendimento com horário')
            ->assertSee('fora do expediente avisa o horário');

        $page->set('newName', 'Antigo')->set('template', 'horario')->call('create')->assertHasErrors(['template']);

        $this->assertSame(0, Workflow::query()->where('name', 'Antigo')->count());
    }

    public function test_card_mostra_resumo_do_rascunho(): void
    {
        $publisher = app(WorkflowPublisher::class);
        $publisher->create('Padrão', $this->user, template: 'padrao');
        $publisher->create('Outro padrão', $this->user, template: 'padrao');
        [$s3, $s4] = Sector::factory()->count(2)->create();
        Workflow::factory()->create(['name' => 'Fixos', 'draft' => ['nodes' => [
            ['id' => 'a', 'type' => 'transferir', 'x' => 0, 'y' => 0, 'data' => ['setor_id' => $s3->id]],
            ['id' => 'b', 'type' => 'transferir', 'x' => 0, 'y' => 0, 'data' => ['setor_id' => $s3->id]],
            ['id' => 'c', 'type' => 'transferir', 'x' => 0, 'y' => 0, 'data' => ['setor_id' => $s4->id]],
        ], 'edges' => []]]);

        $this->page()
            ->assertSeeInOrder(['Fixos', '3 blocos', '2 setores', 'Outro padrão', '8 blocos', 'Padrão', '8 blocos']);
    }

    /** @param  list<int|string>  $fixed */
    private function flowWithMenu(string $name, array $fixed): void
    {
        $nodes = [['id' => 'm', 'type' => 'menu_setores', 'x' => 0, 'y' => 0, 'data' => []]];
        foreach ($fixed as $i => $id) {
            $nodes[] = ['id' => "t$i", 'type' => 'transferir', 'x' => 0, 'y' => 0, 'data' => ['setor_id' => $id]];
        }
        Workflow::factory()->create(['name' => $name, 'draft' => ['nodes' => $nodes, 'edges' => []]]);
    }

    public function test_menu_de_setores_conta_os_setores_que_o_menu_mostra(): void
    {
        $sectors = Sector::factory()->count(5)->create();
        $this->flowWithMenu('Menu', [$sectors[0]->id]);

        $this->page()->assertSee('5 setores');
    }

    public function test_setor_fora_do_menu_em_transferir_soma_uma_vez(): void
    {
        Sector::factory()->count(2)->create();
        $hidden = Sector::factory()->create(['status' => SectorStatus::FORA_DO_MENU]);
        $this->flowWithMenu('Menu', [$hidden->id]);

        $this->page()->assertSee('3 setores');
    }

    public function test_sem_menu_de_setores_conta_so_os_fixos(): void
    {
        Sector::factory()->count(3)->create();
        $id = Sector::query()->firstOrFail()->id;
        $node = fn (string $i): array => ['id' => $i, 'type' => 'transferir', 'x' => 0, 'y' => 0, 'data' => ['setor_id' => $id]];
        Workflow::factory()->create(['name' => 'Fixos', 'draft' => ['nodes' => [$node('a'), $node('b')], 'edges' => []]]);

        $this->page()->assertSee('1 setor')->assertDontSee('1 setores');
    }

    public function test_setor_id_string_no_rascunho_conta_uma_vez(): void
    {
        $sector = Sector::factory()->create();
        $this->flowWithMenu('Menu', [(string) $sector->id]);

        $this->page()->assertSee('1 setor')->assertDontSee('2 setores');
    }

    public function test_setor_id_que_nao_e_inteiro_nao_conta(): void
    {
        $sector = Sector::factory()->create();
        $other = Sector::factory()->create();
        Sector::factory()->create(['id' => 1000]);
        // "B.0" e "1e3" valem outros setores se forem lidos como número; só o inteiro (ou seu texto) conta.
        $this->flowWithMenu('Fixos', []);
        Workflow::query()->where('name', 'Fixos')->update(['draft' => ['nodes' => array_map(
            fn (mixed $id, int $i): array => ['id' => "t$i", 'type' => 'transferir', 'x' => 0, 'y' => 0, 'data' => ['setor_id' => $id]],
            [$sector->id, (string) $sector->id, "{$other->id}.0", '1e3', null],
            range(0, 4),
        ), 'edges' => []]]);

        $this->page()->assertSee('1 setor')->assertDontSee('2 setores')->assertDontSee('3 setores');
    }

    public function test_setor_desativado_ou_apagado_nao_conta_no_menu(): void
    {
        Sector::factory()->create();
        Sector::factory()->create(['status' => SectorStatus::DESATIVADO]);
        Sector::factory()->create()->delete();
        $this->flowWithMenu('Menu', []);

        $this->page()->assertSee('1 setor')->assertDontSee('2 setores')->assertDontSee('3 setores');
    }

    public function test_setor_fixo_apagado_nao_conta(): void
    {
        $sector = Sector::factory()->create();
        $sector->delete();
        $node = ['id' => 'a', 'type' => 'transferir', 'x' => 0, 'y' => 0, 'data' => ['setor_id' => $sector->id]];
        Workflow::factory()->create(['name' => 'Fixos', 'draft' => ['nodes' => [$node], 'edges' => []]]);

        $this->page()->assertSee('1 bloco')->assertDontSee('1 setor');
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

        $this->assertCount(8, $workflow->fresh()?->draft['nodes']);
    }

    private function xpath(string $html): \DOMXPath
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new \DOMXPath($dom);
    }

    public function test_automacao_no_painel_do_desenho(): void
    {
        $panel = fn (string $title): string => '//*[@data-panel][./*[@data-panel-header]//h2[normalize-space()="'.$title.'"]]';

        $empty = $this->xpath($this->page()->html());
        $this->assertSame(1, $empty->query("//section[contains(concat(' ', normalize-space(@class), ' '), ' max-w-[120rem] ')]")->length);
        $this->assertSame(1, $empty->query($panel('Workflows').'//*[@data-empty-state]')->length);

        $workflow = Workflow::factory()->create(['name' => 'Fluxo X']);
        $xpath = $this->xpath($this->page()->set('showNew', true)->html());
        $this->assertSame(1, $xpath->query($panel('Workflows').'//*[@*[name()="wire:key"]="workflow-'.$workflow->id.'"]')->length);
        $this->assertSame('1 workflow', trim($xpath->query($panel('Workflows').'//*[@data-panel-subtitle]')->item(0)?->textContent ?? ''));
        $this->assertSame(1, $xpath->query($panel('Novo workflow').'//form[@*[name()="wire:submit"]="create"]')->length);

        Workflow::factory()->create(['name' => 'Fluxo Y']);
        $two = $this->xpath($this->page()->html());
        $this->assertSame('2 workflows', trim($two->query($panel('Workflows').'//*[@data-panel-subtitle]')->item(0)?->textContent ?? ''));
    }
}
