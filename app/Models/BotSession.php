<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\BotSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Estado da conversa do cliente com o workflow (uma por ticket).
 *
 * @property int $id
 * @property int $ticket_id
 * @property int $workflow_version_id
 * @property string|null $node_id
 * @property array<string, mixed> $vars
 * @property int $attempts
 * @property string|null $waiting `menu` | `input` | nulo
 * @property CarbonImmutable|null $expires_at
 * @property string $state `ativa` | `transferida` | `encerrada` | `abandonada`
 * @property int|null $last_message_id
 */
#[Fillable(['ticket_id', 'workflow_version_id', 'node_id', 'vars', 'attempts', 'waiting', 'expires_at', 'state', 'last_message_id'])]
class BotSession extends Model
{
    /** @use HasFactory<BotSessionFactory> */
    use HasFactory;

    public const ACTIVE = 'ativa';

    public const TRANSFERRED = 'transferida';

    public const CLOSED = 'encerrada';

    public const ABANDONED = 'abandonada';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['vars' => 'array', 'expires_at' => 'datetime', 'attempts' => 'integer'];
    }

    /** @param  Builder<BotSession>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('state', self::ACTIVE);
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<WorkflowVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'workflow_version_id');
    }
}
