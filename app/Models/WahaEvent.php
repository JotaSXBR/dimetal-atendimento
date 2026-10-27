<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Envelope bruto recebido do WAHA, guardado antes de qualquer regra de negócio.
 *
 * @property int $id
 * @property string $waha_event_id
 * @property int|null $channel_id
 * @property string $event
 * @property string|null $session
 * @property array<string, mixed> $payload
 * @property Carbon $received_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['waha_event_id', 'channel_id', 'event', 'session', 'payload', 'received_at'])]
class WahaEvent extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }
}
