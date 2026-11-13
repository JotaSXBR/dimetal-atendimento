<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma linha da auditoria do sistema. Só se acrescenta; nunca se edita. O `detail` nunca guarda senha nem token.
 *
 * @property int $id
 * @property int|null $actor_user_id
 * @property string|null $actor_label
 * @property string $action
 * @property string|null $subject
 * @property string|null $ip
 * @property array<string, mixed>|null $detail
 * @property CarbonImmutable $created_at
 */
#[Fillable(['actor_user_id', 'actor_label', 'action', 'subject', 'ip', 'detail'])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['detail' => 'array'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
