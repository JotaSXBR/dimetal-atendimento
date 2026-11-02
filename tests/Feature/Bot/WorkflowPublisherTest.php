<?php

namespace Tests\Feature\Bot;

use App\Models\BotSession;
use App\Models\Channel;
use App\Models\Sector;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Services\Bot\DefaultWorkflow;
use App\Services\Bot\Flow\FlowValidator;
use App\Services\Bot\InvalidFlowException;
use App\Services\Bot\WorkflowPublisher;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class WorkflowPublisherTest extends TestCase
{
    use RefreshDatabase;

    private WorkflowPublisher $publisher;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publisher = app(WorkflowPublisher::class);
        $this->user = User::factory()->create();
    }

    /** @return array<string, mixed> */
    private function graph(string $text): array
    {
        return [
            'nodes' => [
                ['id' => 'i', 'type' => 'inicio', 'x' => 0, 'y' => 0, 'data' => []],
                ['id' => 'm', 'type' => 'mensagem', 'x' => 0, 'y' => 0, 'data' => ['texto' => $text]],
                ['id' => 't', 'type' => 'transferir', 'x' => 0, 'y' => 0, 'data' => []],
            ],
            'edges' => [['from' => 'i', 'port' => 'out', 'to' => 'm'], ['from' => 'm', 'port' => 'out', 'to' => 't']],
        ];
    }

    private function workflow(string $text = 'Olá'): Workflow
    {
        return Workflow::factory()->create(['draft' => $this->graph($text)]);
    }

    public function test_o_fluxo_padrao_e_publicavel(): void
    {
        Sector::factory()->create();

        $this->assertSame([], (new FlowValidator)->validate(DefaultWorkflow::graph()));
    }

    public function test_criar_do_modelo_copia_o_fluxo_padrao_para_o_rascunho(): void
    {
        $workflow = $this->publisher->create('Atendimento padrão', $this->user, fromTemplate: true);

        $this->assertSame(DefaultWorkflow::graph(), $workflow->draft);
        $this->assertSame($this->user->id, $workflow->updated_by);
        $this->assertSame(0, $workflow->versions()->count());
        $this->assertSame(1, $this->publisher->create('Vazio', $this->user)->draft['nodes'][0]['type'] === 'inicio' ? 1 : 0);
    }

    public function test_publicar_grafo_invalido_nao_grava_versao_e_devolve_os_erros(): void
    {
        $workflow = Workflow::factory()->create(['draft' => ['nodes' => [], 'edges' => []]]);

        try {
            $this->publisher->publish($workflow, $this->user);
            $this->fail('Devia recusar');
        } catch (InvalidFlowException $e) {
            $this->assertNotEmpty($e->errors);
        }

        $this->assertSame(0, $workflow->versions()->count());
    }

    public function test_versoes_numeram_em_sequencia_e_guardam_o_grafo_do_momento(): void
    {
        $workflow = $this->workflow('v1');
        $first = $this->publisher->publish($workflow, $this->user);

        $this->publisher->saveDraft($workflow, $this->graph('v2'), $this->user);
        $second = $this->publisher->publish($workflow, $this->user);

        $this->assertSame([1, 2], [$first->number, $second->number]);
        $this->assertSame('v1', $first->fresh()?->graph['nodes'][1]['data']['texto']);
        $this->assertSame('v2', $second->fresh()?->graph['nodes'][1]['data']['texto']);
        $this->assertSame($this->user->id, $second->published_by);
    }

    public function test_conversa_em_andamento_fica_na_versao_em_que_comecou(): void
    {
        $workflow = $this->workflow('v1');
        $first = $this->publisher->publish($workflow, $this->user);
        $session = BotSession::factory()->create(['workflow_version_id' => $first->id]);

        $this->publisher->saveDraft($workflow, $this->graph('v2'), $this->user);
        $this->publisher->publish($workflow, $this->user);

        $this->assertSame(1, $session->fresh()?->version()->first()?->number);
    }

    public function test_restaurar_versao_antiga_vai_para_o_rascunho_e_publicar_gera_numero_novo(): void
    {
        $workflow = $this->workflow('v1');
        $first = $this->publisher->publish($workflow, $this->user);
        $this->publisher->saveDraft($workflow, $this->graph('v2'), $this->user);
        $this->publisher->publish($workflow, $this->user);

        $this->publisher->restore($workflow, $first, $this->user);
        $this->assertSame('v1', $workflow->fresh()?->draft['nodes'][1]['data']['texto']);

        $third = $this->publisher->publish($workflow, $this->user);
        $this->assertSame(3, $third->number);
        $this->assertSame('v1', $third->graph['nodes'][1]['data']['texto']);
    }

    public function test_restaurar_versao_de_outro_workflow_e_recusado(): void
    {
        $other = WorkflowVersion::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->publisher->restore($this->workflow(), $other, $this->user);
    }

    public function test_ativar_exige_versao_publicada_e_desativar_limpa_o_canal(): void
    {
        $workflow = $this->workflow();
        $channel = Channel::factory()->create();

        try {
            $this->publisher->activate($workflow, $channel);
            $this->fail('Devia recusar sem versão publicada');
        } catch (DomainException) {
            $this->assertNull($channel->fresh()?->workflow_id);
        }

        $this->publisher->publish($workflow, $this->user);
        $this->publisher->activate($workflow, $channel);
        $this->assertSame($workflow->id, $channel->fresh()?->workflow_id);

        $this->publisher->deactivate($channel);
        $this->assertNull($channel->fresh()?->workflow_id);
    }

    public function test_apagar_workflow_em_uso_e_recusado(): void
    {
        $workflow = $this->workflow();
        $this->publisher->publish($workflow, $this->user);
        $channel = Channel::factory()->create();
        $this->publisher->activate($workflow, $channel);

        try {
            $this->publisher->delete($workflow);
            $this->fail('Devia recusar');
        } catch (DomainException) {
            $this->assertNull($workflow->fresh()?->deleted_at);
        }

        $this->publisher->deactivate($channel);
        $this->publisher->delete($workflow);
        $this->assertNotNull(Workflow::withTrashed()->find($workflow->id)?->deleted_at);
    }

    public function test_duplicar_copia_o_rascunho_sem_versoes(): void
    {
        $workflow = $this->workflow('v1');
        $this->publisher->publish($workflow, $this->user);

        $copy = $this->publisher->duplicate($workflow, $this->user);

        $this->assertNotSame($workflow->id, $copy->id);
        $this->assertSame($workflow->name.' (cópia)', $copy->name);
        $this->assertSame($workflow->draft, $copy->draft);
        $this->assertSame(0, $copy->versions()->count());
    }
}
