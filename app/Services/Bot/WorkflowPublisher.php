<?php

namespace App\Services\Bot;

use App\Models\Channel;
use App\Models\Sector;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowVersion;
use App\Services\Bot\Flow\FlowValidator;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Tudo que muda um workflow: rascunho, publicação (versão imutável), restauração, ativação por canal, cópia e exclusão. */
class WorkflowPublisher
{
    public function __construct(private readonly FlowValidator $validator) {}

    public function create(string $name, User $by, bool $fromTemplate = false): Workflow
    {
        return Workflow::create([
            'name' => $name,
            'draft' => $fromTemplate ? DefaultWorkflow::graph() : DefaultWorkflow::blank(),
            'updated_by' => $by->id,
        ]);
    }

    /** @param  array<string, mixed>  $graph */
    public function saveDraft(Workflow $workflow, array $graph, User $by): void
    {
        $workflow->update(['draft' => $graph, 'updated_by' => $by->id]);
    }

    /**
     * Valida o rascunho e grava a próxima versão.
     *
     * @return list<string> os erros do rascunho atual, sem publicar nada
     */
    public function check(Workflow $workflow): array
    {
        return $this->validator->validate(
            $workflow->draft,
            array_values(Sector::query()->get(['id'])->map(fn (Sector $s): int => $s->id)->all()),
            array_values(Tag::query()->get(['id'])->map(fn (Tag $t): int => $t->id)->all()),
        );
    }

    /** @throws InvalidFlowException quando o rascunho tem erros */
    public function publish(Workflow $workflow, User $by): WorkflowVersion
    {
        return DB::transaction(function () use ($workflow, $by): WorkflowVersion {
            // O lock da linha serializa duas publicações simultâneas: o número não se repete.
            $locked = Workflow::query()->lockForUpdate()->findOrFail($workflow->id);
            $errors = $this->check($locked);

            if ($errors !== []) {
                throw new InvalidFlowException($errors);
            }

            $last = $locked->versions()->max('number');

            return WorkflowVersion::create([
                'workflow_id' => $locked->id,
                'number' => (is_numeric($last) ? (int) $last : 0) + 1,
                'graph' => $locked->draft,
                'published_by' => $by->id,
                'published_at' => now(),
            ]);
        });
    }

    /** Copia uma versão antiga para o rascunho; publicar em seguida gera um número novo. */
    public function restore(Workflow $workflow, WorkflowVersion $version, User $by): void
    {
        if ($version->workflow_id !== $workflow->id) {
            throw new InvalidArgumentException('A versão não pertence a este workflow.');
        }

        $this->saveDraft($workflow, $version->graph, $by);
    }

    /** @throws DomainException sem versão publicada */
    public function activate(Workflow $workflow, Channel $channel): void
    {
        if ($workflow->latestVersion() === null) {
            throw new DomainException('Publique o workflow antes de ativá-lo em um canal.');
        }

        $channel->update(['workflow_id' => $workflow->id]);
    }

    public function deactivate(Channel $channel): void
    {
        $channel->update(['workflow_id' => null]);
    }

    public function duplicate(Workflow $workflow, User $by): Workflow
    {
        return Workflow::create(['name' => $workflow->name.' (cópia)', 'draft' => $workflow->draft, 'updated_by' => $by->id]);
    }

    /** @throws DomainException enquanto algum canal usa o workflow */
    public function delete(Workflow $workflow): void
    {
        if (Channel::query()->where('workflow_id', $workflow->id)->exists()) {
            throw new DomainException('Desative o workflow nos canais antes de apagar.');
        }

        $workflow->delete();
    }
}
