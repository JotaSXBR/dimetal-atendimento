<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\WorkflowVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Versão publicada: imutável. Conversas em andamento terminam na versão em que começaram.
 *
 * @property int $id
 * @property int $workflow_id
 * @property int $number
 * @property array<string, mixed> $graph
 * @property int|null $published_by
 * @property CarbonImmutable $published_at
 */
#[Fillable(['workflow_id', 'number', 'graph', 'published_by', 'published_at'])]
class WorkflowVersion extends Model
{
    /** @use HasFactory<WorkflowVersionFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Versão publicada do workflow não pode ser alterada.');
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['graph' => 'array', 'published_at' => 'datetime'];
    }

    /** @return BelongsTo<Workflow, $this> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class)->withTrashed();
    }
}
