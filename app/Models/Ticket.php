<?php

namespace App\Models;

use App\Enums\ClosedReason;
use App\Enums\CloseOutcome;
use App\Enums\TicketStatus;
use App\Services\Inbox\TicketProtocol;
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
 * @property string|null $protocol
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
 * @property CarbonImmutable|null $waiting_since
 * @property CarbonImmutable|null $sla_warn_at
 * @property CarbonImmutable|null $sla_due_at
 * @property CarbonImmutable|null $sla_warned_at
 * @property CarbonImmutable|null $sla_breached_at
 * @property ClosedReason|null $closed_reason
 * @property CloseOutcome|null $close_outcome
 * @property string|null $close_summary
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'protocol',
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
    'closed_reason',
    'close_outcome',
    'close_summary',
])]
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::creating(function (Ticket $ticket): void {
            $ticket->protocol ??= TicketProtocol::next($ticket->opened_at ?? now());
        });
    }

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
            'waiting_since' => 'datetime',
            'sla_warn_at' => 'datetime',
            'sla_due_at' => 'datetime',
            'sla_warned_at' => 'datetime',
            'sla_breached_at' => 'datetime',
            'closed_reason' => ClosedReason::class,
            'close_outcome' => CloseOutcome::class,
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
     * Supervisor/admin veem tudo; inativo, nada. O atendente vê os que assumiu, a fila `aguardando` sem setor ou de
     * um dos seus setores e os `em_atendimento` dos seus setores (para poder tomar o ticket de um colega).
     *
     * @param  Builder<Ticket>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if (! $user->active) {
            $query->whereRaw('false');

            return;
        }

        if ($user->canSeeAllSectors()) {
            return;
        }

        $mySectors = $user->sectors()->select('sectors.id');

        $query->where(fn (Builder $q) => $q
            ->where('assigned_user_id', $user->id)
            ->orWhere(fn (Builder $queue) => $queue
                ->where('status', TicketStatus::AGUARDANDO->value)
                ->where(fn (Builder $sector) => $sector->whereNull('sector_id')->orWhereIn('sector_id', $mySectors)))
            ->orWhere(fn (Builder $active) => $active
                ->where('status', TicketStatus::EM_ATENDIMENTO->value)
                ->whereIn('sector_id', $mySectors)));
    }

    /**
     * O histórico: só encerrados. Supervisor/admin veem todos; inativo, nada. O atendente vê os que atendeu e os dos seus
     * setores; encerrado sem setor e sem ele como responsável fica só para supervisor e admin.
     *
     * @param  Builder<Ticket>  $query
     */
    public function scopeInHistoryOf(Builder $query, User $user): void
    {
        $query->where('status', TicketStatus::ENCERRADO->value);

        if (! $user->active) {
            $query->whereRaw('false');

            return;
        }

        if ($user->canSeeAllSectors()) {
            return;
        }

        $query->where(fn (Builder $q) => $q
            ->where('assigned_user_id', $user->id)
            ->orWhereIn('sector_id', $user->sectors()->select('sectors.id')));
    }

    /**
     * Tudo o que o usuário pode abrir: a fila e os atendimentos (`visibleTo`) mais o histórico (`inHistoryOf`). Supervisor
     * e admin são tratados antes do OR: o `visibleTo` deles não põe condição, e um grupo vazio quebraria o `orWhere`.
     *
     * @param  Builder<Ticket>  $query
     */
    public function scopeReadableBy(Builder $query, User $user): void
    {
        if (! $user->active) {
            $query->whereRaw('false');

            return;
        }

        if ($user->canSeeAllSectors()) {
            return;
        }

        $query->where(fn (Builder $q) => $q
            ->visibleTo($user)
            ->orWhere(fn (Builder $history) => $history->inHistoryOf($user)));
    }

    /**
     * Registra uma mensagem recebida. Atômico e só avança: um evento atrasado nunca recua o horário.
     */
    public function recordInbound(CarbonInterface $at): void
    {
        $at = $this->databaseTime($at);

        // `waiting_since` é desde quando o cliente espera resposta humana: só a primeira mensagem sem resposta conta, e uma
        // mensagem atrasada, de antes da última resposta, não abre espera nenhuma.
        DB::update(
            'update tickets set last_inbound_at = greatest(last_inbound_at, ?),'
            .' waiting_since = case when waiting_since is not null then waiting_since'
            .' when last_outbound_at is null or ? > last_outbound_at then ? else null end,'
            .' updated_at = ? where id = ?',
            [$at, $at, $at, now(), $this->id],
        );

        $this->refresh();
    }

    /**
     * Registra uma resposta humana (painel ou celular). Também marca a primeira resposta, uma única vez, e encerra a espera do
     * cliente (e as marcas de SLA), a menos que a resposta seja de antes de ele escrever (eco tardio, caixa de saída): essa não
     * responde à espera que está aberta.
     */
    public function recordOutbound(CarbonInterface $at): void
    {
        $at = $this->databaseTime($at);
        $answers = 'waiting_since is not null and waiting_since <= ?';

        DB::update(
            'update tickets set last_outbound_at = greatest(last_outbound_at, ?), first_human_reply_at = coalesce(first_human_reply_at, ?),'
            ." sla_warn_at = case when {$answers} then null else sla_warn_at end,"
            ." sla_due_at = case when {$answers} then null else sla_due_at end,"
            ." sla_warned_at = case when {$answers} then null else sla_warned_at end,"
            ." sla_breached_at = case when {$answers} then null else sla_breached_at end,"
            ." waiting_since = case when {$answers} then null else waiting_since end,"
            .' updated_at = ? where id = ?',
            [$at, $at, $at, $at, $at, $at, $at, now(), $this->id],
        );

        $this->refresh();
    }

    /**
     * `estourou` (passou do vencimento), `perto` (passou do aviso) ou `null`. Calculado na hora de desenhar a tela, a partir das
     * datas gravadas pela rotina: o selo muda no instante certo mesmo que a rotina ainda não tenha rodado. Sem espera ou
     * encerrado, `null`.
     */
    public function slaState(?CarbonInterface $now = null): ?string
    {
        if ($this->status === TicketStatus::ENCERRADO || $this->waiting_since === null) {
            return null;
        }

        $now ??= now();

        return match (true) {
            $this->sla_due_at !== null && $this->sla_due_at <= $now => 'estourou',
            $this->sla_warn_at !== null && $this->sla_warn_at <= $now => 'perto',
            default => null,
        };
    }

    /** O instante no fuso do banco: a coluna é `timestamp` sem fuso e o PDO formata no fuso do próprio objeto. */
    private function databaseTime(CarbonInterface $at): CarbonImmutable
    {
        return CarbonImmutable::instance($at)->setTimezone(config()->string('app.timezone'));
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
