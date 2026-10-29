<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Data em que o atendimento fica fechado o dia todo.
 *
 * @property int $id
 * @property CarbonImmutable $date
 * @property string $name
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['date', 'name'])]
class Holiday extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['date' => 'date'];
    }
}
