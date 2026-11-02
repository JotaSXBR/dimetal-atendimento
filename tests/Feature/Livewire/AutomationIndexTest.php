<?php

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Livewire\Automation\Index;
use App\Models\Channel;
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
        $workflow = $publisher->create($name, $this->user, fromTemplate: true);
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
        $this->page()->set('newName', 'Triagem')->set('fromTemplate', false)->call('create')->assertHasNoErrors();
        $this->page()->set('newName', 'Do modelo')->set('fromTemplate', true)->call('create');

        $this->assertSame(['Do modelo', 'Triagem'], Workflow::query()->orderBy('name')->pluck('name')->all());
        $this->assertCount(5, Workflow::query()->where('name', 'Do modelo')->firstOrFail()->draft['nodes']);
        $this->assertCount(1, Workflow::query()->where('name', 'Triagem')->firstOrFail()->draft['nodes']);
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
