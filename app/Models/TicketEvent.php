<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trilha de auditoria do ticket. Só se acrescenta; nunca se edita.
 *
 * @property int $id
 * @property int $ticket_id
 * @property string $type
 * @property int|null $actor_user_id
 * @property array<string, mixed>|null $payload
 * @property CarbonImmutable $created_at
 */
#[Fillable(['ticket_id', 'type', 'actor_user_id', 'payload'])]
class TicketEvent extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['payload' => 'array'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * @return BelongsTo<Ticket, $this>
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
