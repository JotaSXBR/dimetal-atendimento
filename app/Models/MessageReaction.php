<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reação (emoji) a uma mensagem. Não é mensagem: não muda última atividade, espera de resposta nem SLA do ticket.
 *
 * @property int $id
 * @property int $message_id
 * @property string $by
 * @property string $emoji
 * @property CarbonImmutable $reacted_at
 */
#[Fillable(['message_id', 'by', 'emoji', 'reacted_at'])]
class MessageReaction extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['reacted_at' => 'datetime'];
    }

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
