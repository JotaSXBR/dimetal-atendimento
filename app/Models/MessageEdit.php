<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma versão anterior de uma mensagem editada pelo cliente (ou pelo celular da empresa).
 *
 * @property int $id
 * @property int $message_id
 * @property string $previous_body
 * @property CarbonImmutable $edited_at
 */
#[Fillable(['message_id', 'previous_body', 'edited_at'])]
class MessageEdit extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['edited_at' => 'datetime'];
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
