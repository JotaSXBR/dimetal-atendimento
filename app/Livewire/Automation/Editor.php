<?php

namespace App\Livewire\Automation;

use App\Models\Sector;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Services\Bot\Flow\NodeType;
use App\Services\Bot\InvalidFlowException;
use App\Services\Bot\WorkflowPublisher;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * O editor visual de um workflow. O canvas vive no navegador (resources/js/flow-editor); aqui chega o grafo inteiro a
 * cada gravação, e o servidor confere a forma antes de guardar. Quem manda no que é publicável é o `FlowValidator`.
 */
#[Title('Editor de workflow')]
class Editor extends Component
{
    private const MAX_NODES = 300;

    private const MAX_EDGES = 600;

    private const MAX_BYTES = 524288;

    public Workflow $workflow;

    /**
     * A resposta da última ação (também devolvida ao navegador).
     *
     * @var array<string, mixed>
     */
    public array $result = [];

    public function mount(Workflow $workflow): void
    {
        $this->authorize('supervisor');

        $this->workflow = $workflow;
    }

    /** @param  array<string, mixed>  $graph */
    public function saveDraft(array $graph, WorkflowPublisher $publisher): void
    {
        $this->authorize('supervisor');

        if (! $this->accept($graph)) {
            return;
        }

        $publisher->saveDraft($this->workflow, $graph, $this->user());
        $this->result = ['ok' => true];
    }

    /**
     * @param  array<string, mixed>  $graph
     * @return array<string, mixed>
     */
    public function validateDraft(array $graph, WorkflowPublisher $publisher): array
    {
        $this->authorize('supervisor');

        if (! $this->accept($graph)) {
            return $this->result;
        }

        $publisher->saveDraft($this->workflow, $graph, $this->user());

        return $this->result = ['ok' => true, 'errors' => $publisher->check($this->workflow->refresh())];
    }

    /**
     * @param  array<string, mixed>  $graph
     * @return array<string, mixed>
     */
    public function publish(array $graph, WorkflowPublisher $publisher): array
    {
        $this->authorize('supervisor');

        if (! $this->accept($graph)) {
            return $this->result;
        }

        $publisher->saveDraft($this->workflow, $graph, $this->user());

        try {
            $version = $publisher->publish($this->workflow->refresh(), $this->user());
        } catch (InvalidFlowException $e) {
            return $this->result = ['ok' => false, 'errors' => $e->errors];
        }

        return $this->result = ['ok' => true, 'version' => $version->number, 'versions' => $this->versions()];
    }

    /** @return array<string, mixed> */
    public function restore(int $versionId, WorkflowPublisher $publisher): array
    {
        $this->authorize('supervisor');

        $version = WorkflowVersion::query()->where('workflow_id', $this->workflow->id)->findOrFail($versionId);
        $publisher->restore($this->workflow, $version, $this->user());

        return $this->result = ['ok' => true, 'graph' => $this->workflow->refresh()->draft];
    }

    /**
     * A forma do grafo que chega do navegador: tamanho, tipos conhecidos e ids simples. O conteúdo dos blocos é conferido
     * ao publicar e, na execução, o motor ignora o que não reconhece.
     *
     * @param  array<string, mixed>  $graph
     */
    private function accept(array $graph): bool
    {
        $nodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];
        $edges = is_array($graph['edges'] ?? null) ? $graph['edges'] : [];
        $error = match (true) {
            count($nodes) > self::MAX_NODES || count($edges) > self::MAX_EDGES => 'O workflow passou do tamanho máximo.',
            strlen((string) json_encode($graph)) > self::MAX_BYTES => 'O workflow passou do tamanho máximo.',
            array_filter($nodes, fn (mixed $n): bool => ! $this->knownNode($n)) !== [] => 'O workflow tem um bloco de tipo desconhecido.',
            default => null,
        };

        if ($error === null) {
            return true;
        }

        $this->addError('graph', $error);
        $this->result = ['ok' => false, 'errors' => [$error]];

        return false;
    }

    /** Bloco com id simples e tipo que o motor conhece. */
    private function knownNode(mixed $node): bool
    {
        return is_array($node)
            && is_string($node['id'] ?? null) && strlen($node['id']) <= 40
            && is_string($node['type'] ?? null) && NodeType::tryFrom($node['type']) !== null;
    }

    /** @return list<array{id: int, number: int, at: string, by: string}> */
    private function versions(): array
    {
        return array_values($this->workflow->versions()->with('publisher')->orderByDesc('number')->get()
            ->map(fn (WorkflowVersion $v): array => [
                'id' => $v->id,
                'number' => $v->number,
                'at' => $v->published_at->timezone(config()->string('app.display_timezone'))->format('d/m/Y H:i'),
                'by' => $v->publisher->name ?? 'usuário removido',
            ])->all());
    }

    private function user(): User
    {
        $user = auth()->user();
        assert($user instanceof User);

        return $user;
    }

    public function render(): View
    {
        return view('livewire.automation.editor', [
            'config' => [
                'graph' => $this->workflow->draft,
                'sectors' => Sector::query()->orderBy('menu_order')->get(['id', 'name'])->map(fn (Sector $s): array => ['id' => $s->id, 'name' => $s->name])->all(),
                'tags' => Tag::query()->orderBy('name')->get(['id', 'name'])->map(fn (Tag $t): array => ['id' => $t->id, 'name' => $t->name])->all(),
                'versions' => $this->versions(),
            ],
        ]);
    }
}
