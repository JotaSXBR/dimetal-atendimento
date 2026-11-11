<?php

namespace App\Livewire\Automation;

use App\Models\Channel;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Services\Bot\WorkflowPublisher;
use App\Services\Bot\WorkflowTemplates;
use DomainException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Automação')]
class Index extends Component
{
    public bool $showNew = false;

    public string $newName = '';

    public string $template = WorkflowTemplates::STANDARD;

    /** O workflow com a lista de versões aberta. */
    public ?int $expanded = null;

    public ?string $error = null;

    /** Aviso depois de criar de um modelo: os setores que ele cita e esta instalação não tem. */
    public ?string $notice = null;

    public function mount(): void
    {
        $this->authorize('supervisor');
    }

    public function create(WorkflowPublisher $publisher): void
    {
        $this->authorize('supervisor');

        $this->newName = trim($this->newName);
        $this->validate(['newName' => ['required', 'string', 'max:120']], [
            'newName.required' => 'Dê um nome ao workflow.',
            'newName.max' => 'O nome pode ter até 120 caracteres.',
        ]);

        $missing = WorkflowTemplates::resolve($this->template)['missing'];
        $publisher->create($this->newName, $this->user(), $this->template);
        $this->reset('showNew', 'newName', 'template');
        $this->error = null;
        $this->notice = $missing === [] ? null : 'Criado. Estes setores do modelo não existem aqui: '.implode(', ', $missing).'. Escolha o setor nos blocos Transferir.';
    }

    public function duplicate(int $id, WorkflowPublisher $publisher): void
    {
        $this->authorize('supervisor');

        $publisher->duplicate(Workflow::query()->findOrFail($id), $this->user());
    }

    public function delete(int $id, WorkflowPublisher $publisher): void
    {
        $this->authorize('supervisor');

        $this->attempt(fn () => $publisher->delete(Workflow::query()->findOrFail($id)));
    }

    public function activate(int $workflowId, int $channelId, WorkflowPublisher $publisher): void
    {
        $this->authorize('supervisor');

        $this->attempt(fn () => $publisher->activate(Workflow::query()->findOrFail($workflowId), Channel::query()->findOrFail($channelId)));
    }

    public function deactivate(int $channelId, WorkflowPublisher $publisher): void
    {
        $this->authorize('supervisor');

        $publisher->deactivate(Channel::query()->findOrFail($channelId));
        $this->error = null;
    }

    public function toggle(int $id): void
    {
        $this->expanded = $this->expanded === $id ? null : $id;
    }

    public function restore(int $workflowId, int $versionId, WorkflowPublisher $publisher): void
    {
        $this->authorize('supervisor');

        $workflow = Workflow::query()->findOrFail($workflowId);
        $publisher->restore($workflow, WorkflowVersion::query()->where('workflow_id', $workflow->id)->findOrFail($versionId), $this->user());
        $this->error = null;
    }

    private function attempt(\Closure $action): void
    {
        $this->error = null;

        try {
            $action();
        } catch (DomainException $e) {
            $this->error = $e->getMessage();
        }
    }

    private function user(): User
    {
        $user = auth()->user();
        assert($user instanceof User);

        return $user;
    }

    /**
     * Resumo do rascunho para o cartão: quantos blocos e quantos setores ele cita (os fixos dos blocos Transferir, mais um
     * se há Menu de setores, que leva ao setor que o cliente escolher).
     *
     * @param  array<string, mixed>  $draft
     * @return array{nodes: int, sectors: int}
     */
    private function summary(array $draft): array
    {
        $raw = $draft['nodes'] ?? [];
        $nodes = collect(is_array($raw) ? $raw : []);
        $fixed = $nodes->where('type', 'transferir')->pluck('data.setor_id')->filter(fn ($id): bool => $id !== null && $id !== '')->unique()->count();

        return ['nodes' => $nodes->count(), 'sectors' => $fixed + ($nodes->contains('type', 'menu_setores') ? 1 : 0)];
    }

    public function render(): View
    {
        $workflows = Workflow::query()->withCount('versions')->with(['versions' => fn ($q) => $q->with('publisher')->orderByDesc('number')])->orderBy('name')->get();

        return view('livewire.automation.index', [
            'workflows' => $workflows,
            'summaries' => $workflows->mapWithKeys(fn (Workflow $workflow): array => [$workflow->id => $this->summary($workflow->draft)]),
            'channels' => Channel::query()->orderBy('name')->get(),
            'templates' => WorkflowTemplates::all(),
        ]);
    }
}
