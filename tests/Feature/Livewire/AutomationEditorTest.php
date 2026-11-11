<?php

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Automation\Editor;
use App\Models\User;
use App\Models\Workflow;
use App\Services\Bot\DefaultWorkflow;
use App\Services\Bot\WorkflowPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AutomationEditorTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Workflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['role' => UserRole::SUPERVISOR]);
        $this->workflow = app(WorkflowPublisher::class)->create('Triagem', $this->user, template: true);
    }

    public function test_so_supervisor_e_admin_abrem_o_editor(): void
    {
        $url = '/automacao/'.$this->workflow->id;

        $this->actingAs(User::factory()->create(['role' => UserRole::ATENDENTE]))->get($url)->assertForbidden();
        $this->actingAs($this->user)->get($url)->assertOk()->assertSee('Triagem');
    }

    public function test_salvar_rascunho_grava_o_grafo(): void
    {
        $graph = DefaultWorkflow::blank();
        $graph['nodes'][] = ['id' => 'm', 'type' => 'mensagem', 'x' => 200, 'y' => 0, 'data' => ['texto' => 'Novo']];

        Livewire::actingAs($this->user)->test(Editor::class, ['workflow' => $this->workflow])
            ->call('saveDraft', $graph)
            ->assertHasNoErrors();

        $this->assertSame('Novo', $this->workflow->fresh()?->draft['nodes'][1]['data']['texto']);
        $this->assertSame($this->user->id, $this->workflow->fresh()?->updated_by);
    }

    public function test_grafo_com_tipo_desconhecido_ou_grande_demais_e_recusado(): void
    {
        $bad = DefaultWorkflow::blank();
        $bad['nodes'][] = ['id' => 'x', 'type' => 'magia', 'x' => 0, 'y' => 0, 'data' => []];

        $page = Livewire::actingAs($this->user)->test(Editor::class, ['workflow' => $this->workflow])
            ->call('saveDraft', $bad)
            ->assertHasErrors('graph');

        $big = DefaultWorkflow::blank();
        for ($i = 0; $i < 301; $i++) {
            $big['nodes'][] = ['id' => 'n'.$i, 'type' => 'mensagem', 'x' => 0, 'y' => 0, 'data' => ['texto' => 'x']];
        }
        $page->call('saveDraft', $big)->assertHasErrors('graph');

        $this->assertCount(5, $this->workflow->fresh()?->draft['nodes'], 'o rascunho anterior fica intacto');
    }

    public function test_publicar_grafo_invalido_devolve_os_erros_e_nao_cria_versao(): void
    {
        $page = Livewire::actingAs($this->user)->test(Editor::class, ['workflow' => $this->workflow])
            ->call('publish', DefaultWorkflow::blank());

        $result = $page->get('result');
        $this->assertFalse($result['ok']);
        $this->assertNotEmpty($result['errors']);
        $this->assertSame(0, $this->workflow->versions()->count());
    }

    public function test_publicar_grafo_valido_cria_a_versao(): void
    {
        $page = Livewire::actingAs($this->user)->test(Editor::class, ['workflow' => $this->workflow])
            ->call('publish', DefaultWorkflow::graph());

        $this->assertTrue($page->get('result')['ok']);
        $this->assertSame(1, $page->get('result')['version']);
        $this->assertSame(1, $this->workflow->versions()->count());
    }

    public function test_validar_lista_os_erros_do_rascunho_enviado(): void
    {
        $page = Livewire::actingAs($this->user)->test(Editor::class, ['workflow' => $this->workflow])
            ->call('validateDraft', DefaultWorkflow::blank());

        $this->assertNotEmpty($page->get('result')['errors']);
    }

    public function test_restaurar_versao_devolve_o_grafo_dela_ao_rascunho(): void
    {
        $publisher = app(WorkflowPublisher::class);
        $first = $publisher->publish($this->workflow, $this->user);
        $publisher->saveDraft($this->workflow, DefaultWorkflow::blank(), $this->user);

        $page = Livewire::actingAs($this->user)->test(Editor::class, ['workflow' => $this->workflow])
            ->call('restore', $first->id);

        $this->assertCount(5, $this->workflow->fresh()?->draft['nodes']);
        $this->assertCount(5, $page->get('result')['graph']['nodes']);
    }

    public function test_salvar_rascunho_guarda_as_mensagens(): void
    {
        $graph = [...DefaultWorkflow::blank(), 'settings' => ['mensagens' => ['opcao_invalida' => 'Tente de novo', 'rodape_sair' => '']]];

        Livewire::actingAs($this->user)->test(Editor::class, ['workflow' => $this->workflow])
            ->call('saveDraft', $graph)
            ->assertHasNoErrors();

        $this->assertEquals(['opcao_invalida' => 'Tente de novo', 'rodape_sair' => ''], $this->workflow->fresh()?->draft['settings']['mensagens']);
    }

    /** @return array<string, array{mixed}> */
    public static function badSettings(): array
    {
        return [
            'settings que nao e lista' => ['texto'],
            'mensagens que nao e lista' => [['mensagens' => 'texto']],
            'chave desconhecida' => [['mensagens' => ['magia' => 'x']]],
            'valor que nao e texto' => [['mensagens' => ['opcao_invalida' => 5]]],
            'texto grande demais' => [['mensagens' => ['opcao_invalida' => str_repeat('a', 1001)]]],
        ];
    }

    #[DataProvider('badSettings')]
    public function test_mensagens_invalidas_sao_recusadas_e_nada_e_gravado(mixed $settings): void
    {
        $before = $this->workflow->fresh()?->draft;

        Livewire::actingAs($this->user)->test(Editor::class, ['workflow' => $this->workflow])
            ->call('saveDraft', [...DefaultWorkflow::blank(), 'settings' => $settings])
            ->assertHasErrors('graph');

        $this->assertSame($before, $this->workflow->fresh()?->draft);
    }

    public function test_restaurar_traz_as_mensagens_da_versao(): void
    {
        $publisher = app(WorkflowPublisher::class);
        $publisher->saveDraft($this->workflow, [...DefaultWorkflow::graph(), 'settings' => ['mensagens' => ['opcao_invalida' => 'Da versão']]], $this->user);
        $version = $publisher->publish($this->workflow->fresh() ?? $this->workflow, $this->user);
        $publisher->saveDraft($this->workflow, DefaultWorkflow::graph(), $this->user);

        $page = Livewire::actingAs($this->user)->test(Editor::class, ['workflow' => $this->workflow])->call('restore', $version->id);

        $this->assertSame('Da versão', $page->get('result')['graph']['settings']['mensagens']['opcao_invalida']);
    }

    public function test_pagina_do_editor_traz_o_painel_de_mensagens(): void
    {
        Livewire::actingAs($this->user)->test(Editor::class, ['workflow' => $this->workflow])
            ->assertSee('Mensagens do bot')
            ->assertSee('encaminhamento_setor')
            ->assertSee('rodape_sair');
    }

    public function test_atendente_nao_chama_as_acoes(): void
    {
        Livewire::actingAs(User::factory()->create(['role' => UserRole::ATENDENTE]))
            ->test(Editor::class, ['workflow' => $this->workflow])
            ->assertForbidden();
    }
}
