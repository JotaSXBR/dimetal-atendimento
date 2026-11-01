<?php

namespace App\Livewire\Automation;

use App\Models\Channel;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Services\Bot\WorkflowPublisher;
use DomainException;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Automação')]
class Index extends Component
{
    public bool $showNew = false;

    public string $newName = '';

    public bool $fromTemplate = true;

    /** O workflow com a lista de versões aberta. */
    public ?int $expanded = null;

    public ?string $error = null;

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

        $publisher->create($this->newName, $this->user(), $this->fromTemplate);
        $this->reset('showNew', 'newName');
        $this->fromTemplate = true;
        $this->error = null;
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

    private function user(): \App\Models\User
    {
        $user = auth()->user();
        assert($user instanceof \App\Models\User);

        return $user;
    }

    public function render(): View
    {
        return view('livewire.automation.index', [
            'workflows' => Workflow::query()->withCount('versions')->with(['versions' => fn ($q) => $q->with('publisher')->orderByDesc('number')])->orderBy('name')->get(),
            'channels' => Channel::query()->orderBy('name')->get(),
        ]);
    }
}
