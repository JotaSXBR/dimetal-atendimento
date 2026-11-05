<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TicketNoteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Nota interna: só a equipe vê. Vive fora de `messages` de propósito, para nunca entrar no fluxo de envio ao WhatsApp.
 *
 * @property int $id
 * @property int $ticket_id
 * @property int $user_id
 * @property string $body
 * @property CarbonImmutable $created_at
 */
#[Fillable(['ticket_id', 'user_id', 'body'])]
class TicketNote extends Model
{
    /** @use HasFactory<TicketNoteFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
