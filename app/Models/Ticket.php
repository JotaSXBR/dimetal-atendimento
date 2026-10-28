<?php

namespace App\Models;

use App\Enums\TicketStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $contact_id
 * @property int $channel_id
 * @property int|null $sector_id
 * @property int|null $assigned_user_id
 * @property TicketStatus $status
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $first_human_reply_at
 * @property CarbonImmutable|null $last_inbound_at
 * @property CarbonImmutable|null $last_outbound_at
 * @property CarbonImmutable|null $closed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'contact_id',
    'channel_id',
    'sector_id',
    'assigned_user_id',
    'status',
    'opened_at',
    'first_human_reply_at',
    'last_inbound_at',
    'last_outbound_at',
    'closed_at',
])]
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'opened_at' => 'datetime',
            'first_human_reply_at' => 'datetime',
            'last_inbound_at' => 'datetime',
            'last_outbound_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<Ticket>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', '<>', TicketStatus::ENCERRADO->value);
    }

    /**
     * Registra uma mensagem recebida. Atômico e só avança: um evento atrasado nunca recua o horário.
     */
    public function recordInbound(CarbonInterface $at): void
    {
        DB::update(
            'update tickets set last_inbound_at = greatest(last_inbound_at, ?), updated_at = ? where id = ?',
            [$at, now(), $this->id],
        );

        $this->refresh();
    }

    /**
     * Registra uma resposta humana (painel ou celular). Também marca a primeira resposta, uma única vez.
     */
    public function recordOutbound(CarbonInterface $at): void
    {
        DB::update(
            'update tickets set last_outbound_at = greatest(last_outbound_at, ?), first_human_reply_at = coalesce(first_human_reply_at, ?), updated_at = ? where id = ?',
            [$at, $at, now(), $this->id],
        );

        $this->refresh();
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    /**
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * @return BelongsTo<Sector, $this>
     */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * @return HasMany<TicketEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(TicketEvent::class);
    }
}
