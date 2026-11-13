<?php

namespace App\Livewire\Automation;

use App\Models\Sector;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Services\Bot\BotMessages;
use App\Services\Bot\Flow\NodeType;
use App\Services\Bot\InvalidFlowException;
use App\Services\Bot\Simulation\BotSimulator;
use App\Services\Bot\Simulation\SimulationState;
use App\Services\Bot\WorkflowPublisher;
use Closure;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * O editor visual de um workflow. O canvas vive no navegador (resources/js/flow-editor); aqui chega o grafo inteiro a
 * cada gravação, e o servidor confere a forma antes de guardar. Quem manda no que é publicável é o `FlowValidator`.
 *
 * @phpstan-import-type StateShape from SimulationState
 */
#[Title('Editor de workflow')]
class Editor extends Component
{
    private const MAX_NODES = 300;

    private const MAX_EDGES = 600;

    private const MAX_BYTES = 524288;

    private const SIM_TEXT_MAX = 1000;

    private const CLOCKS = ['real', 'aberto', 'fechado'];

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

    /**
     * A conversa de teste (`SimulationState::toArray()`); vazia sem teste. Só o servidor a escreve.
     *
     * @var StateShape|array{}
     */
    #[Locked]
    public array $sim = [];

    /**
     * Os erros do rascunho que impediram o teste (a lista do "Validar").
     *
     * @var list<string>
     */
    #[Locked]
    public array $simErrors = [];

    public string $simText = '';

    /**
     * Começa a conversa de teste sobre o grafo do canvas (salvo ou não): o mesmo motor do bot, sem gravar nem enviar nada. Com
     * erros no grafo mostra a lista do "Validar" em vez de simular.
     *
     * @param  array<string, mixed>  $graph
     */
    public function simStart(array $graph, string $clock, BotSimulator $simulator, WorkflowPublisher $publisher): void
    {
        $this->authorize('supervisor');
        $this->simReset();

        if (! $this->accept($graph)) {
            return;
        }

        $this->simErrors = $publisher->checkGraph($graph);

        if ($this->simErrors === []) {
            $this->sim = $simulator->start($graph, $this->user()->name, in_array($clock, self::CLOCKS, true) ? $clock : 'real')->toArray();
        }
    }

    /** @param  array<string, mixed>  $graph */
    public function simReply(array $graph, string $text, BotSimulator $simulator): void
    {
        $this->authorize('supervisor');
        $this->resetErrorBag('simText');

        $text = trim($text);

        if ($text === '' || mb_strlen($text) > self::SIM_TEXT_MAX) {
            $this->addError('simText', 'Escreva uma resposta de até '.self::SIM_TEXT_MAX.' caracteres.');

            return;
        }

        $this->simContinue($graph, fn (SimulationState $state): SimulationState => $simulator->reply($graph, $state, $text));
        $this->reset('simText');
    }

    /**
     * O cliente ficou em silêncio até o prazo do bloco que espera.
     *
     * @param  array<string, mixed>  $graph
     */
    public function simSilence(array $graph, BotSimulator $simulator): void
    {
        $this->authorize('supervisor');

        $this->simContinue($graph, fn (SimulationState $state): SimulationState => $simulator->silence($graph, $state));
    }

    public function simReset(): void
    {
        $this->authorize('supervisor');

        $this->reset('sim', 'simErrors', 'simText');
        $this->resetErrorBag('simText');
    }

    /**
     * @param  array<string, mixed>  $graph
     * @param  Closure(SimulationState): SimulationState  $step
     */
    private function simContinue(array $graph, Closure $step): void
    {
        if ($this->sim === [] || ! $this->accept($graph)) {
            return;
        }

        $this->sim = $step(SimulationState::fromArray($this->sim))->toArray();
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
            ! $this->validSettings($graph['settings'] ?? null) => 'As mensagens do bot são inválidas.',
            default => null,
        };

        if ($error === null) {
            return true;
        }

        $this->addError('graph', $error);
        $this->result = ['ok' => false, 'errors' => [$error]];

        return false;
    }

    /** `settings` ausente vale; presente, só as mensagens conhecidas, em texto e dentro do limite. */
    private function validSettings(mixed $settings): bool
    {
        if ($settings === null) {
            return true;
        }

        $messages = is_array($settings) ? ($settings['mensagens'] ?? []) : null;

        return is_array($messages) && array_filter(
            $messages,
            fn (mixed $text, mixed $key): bool => ! in_array($key, BotMessages::KEYS, true) || ! is_string($text) || mb_strlen($text) > BotMessages::MAX_LENGTH,
            ARRAY_FILTER_USE_BOTH,
        ) === [];
    }

    /** Bloco com id simples e tipo que o motor conhece. */
    private function knownNode(mixed $node): bool
    {
        return is_array($node)
            && is_string($node['id'] ?? null) && strlen($node['id']) <= 40
            && is_string($node['type'] ?? null) && NodeType::tryFrom($node['type']) !== null;
    }

    /** @return list<array{key: string, label: string, default: string}> */
    private function messageCatalog(): array
    {
        $labels = [
            'encaminhamento_setor' => 'Aviso ao entregar à fila (com setor)',
            'encaminhamento_geral' => 'Aviso ao entregar à fila (sem setor)',
            'opcao_invalida' => 'Aviso de opção inválida (menu)',
            'resposta_invalida' => 'Aviso de resposta inválida (pergunta)',
            'rodape_sair' => 'Dica de como falar com um atendente (fim do menu)',
        ];

        return array_map(fn (string $key): array => ['key' => $key, 'label' => $labels[$key], 'default' => BotMessages::DEFAULTS[$key]], BotMessages::KEYS);
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
                'tags' => Tag::sortedByName(Tag::query()->get(['id', 'name', 'scope']))->map(fn (Tag $t): array => ['id' => $t->id, 'name' => $t->name, 'scope' => $t->scope->value, 'label' => $t->name.' ('.mb_strtolower($t->scope->label()).')'])->all(),
                'users' => User::query()->where('active', true)->orderBy('name')->get(['id', 'name'])->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name])->all(),
                'versions' => $this->versions(),
                'messages' => $this->messageCatalog(),
            ],
        ]);
    }
}
