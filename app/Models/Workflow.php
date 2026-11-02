<?php

namespace App\Models;

use Database\Factories\WorkflowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $name
 * @property array<string, mixed> $draft
 * @property int|null $updated_by
 */
#[Fillable(['name', 'draft', 'updated_by'])]
class Workflow extends Model
{
    /** @use HasFactory<WorkflowFactory> */
    use HasFactory;

    use SoftDeletes;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['draft' => 'array'];
    }

    /** @return HasMany<WorkflowVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class);
    }

    public function latestVersion(): ?WorkflowVersion
    {
        return $this->versions()->orderByDesc('number')->first();
    }
}
