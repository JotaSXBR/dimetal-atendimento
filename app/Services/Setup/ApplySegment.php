<?php

namespace App\Services\Setup;

use App\Models\Setting;
use App\Models\User;
use App\Models\Workflow;
use App\Services\Bot\DefaultWorkflow;
use App\Services\Bot\WorkflowPublisher;
use App\Services\Bot\WorkflowTemplates;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Aplica o segmento escolhido no assistente, tudo ou nada: Distribuidora instala os padrões; Em branco só grava as marcas. */
final class ApplySegment
{
    public const DISTRIBUIDORA = 'distribuidora';

    public const EM_BRANCO = 'em_branco';

    public function __construct(private readonly WorkflowPublisher $publisher) {}

    public function __invoke(string $segment, User $by): void
    {
        if (! in_array($segment, SetupState::SEGMENTS, true)) {
            throw new InvalidArgumentException("Segmento [{$segment}] inválido.");
        }

        DB::transaction(function () use ($segment, $by): void {
            if (SetupState::segment() !== null) {
                return;
            }

            if ($segment === self::DISTRIBUIDORA) {
                DefaultSectors::install();
                DefaultTags::install();
                $this->publishDefaultWorkflow($by);
            } else {
                Setting::put(DefaultSectors::MARK, true, 'bool');
                Setting::put(DefaultTags::MARK, true, 'bool');
            }

            SetupState::setSegment($segment);
            SetupState::setStep('canal');
        });
    }

    private function publishDefaultWorkflow(User $by): void
    {
        $workflow = Workflow::query()->where('name', DefaultWorkflow::NAME)->first()
            ?? $this->publisher->create(DefaultWorkflow::NAME, $by, WorkflowTemplates::STANDARD);

        if ($workflow->latestVersion() === null) {
            $this->publisher->publish($workflow, $by);
        }
    }
}
