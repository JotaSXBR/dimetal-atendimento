<?php

namespace App\Models;

use App\Enums\UpdateStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Uma atualização do sistema pelo app. `previous_compose` é o compose do Coolify antes da troca (pode ter segredos: fica
 * criptografado); `previous_version` nulo = a variável `FLUXIE_VERSION` não existia.
 *
 * @property int $id
 * @property UpdateStatus $status
 * @property string $from_version
 * @property string $to_version
 * @property int|null $requested_by
 * @property string|null $backup_file
 * @property string|null $previous_compose
 * @property string|null $previous_version
 * @property string|null $message
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $step_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $notified_at
 */
#[Fillable(['status', 'from_version', 'to_version', 'requested_by', 'backup_file', 'previous_compose', 'previous_version', 'message', 'started_at', 'step_at', 'finished_at', 'notified_at'])]
class SystemUpdate extends Model
{
    /** Chave fixa do `pg_advisory_xact_lock` que serializa o início de atualizações. */
    private const START_LOCK = 7_301_170_001;

    /**
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'status' => UpdateStatus::class,
            'previous_compose' => 'encrypted',
            'started_at' => 'datetime',
            'step_at' => 'datetime',
            'finished_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }

    /** A atualização aberta mais recente, se houver. */
    public static function open(): ?self
    {
        return static::query()->whereIn('status', array_column(UpdateStatus::openCases(), 'value'))->latest('id')->first();
    }

    /** Cria a atualização em `conferindo`; nulo se já há uma aberta (a trava vale entre processos). */
    public static function start(?User $by, string $from, string $to): ?self
    {
        return DB::transaction(function () use ($by, $from, $to): ?self {
            DB::select('select pg_advisory_xact_lock(?)', [self::START_LOCK]);

            return static::open() !== null ? null : static::query()->create([
                'status' => UpdateStatus::Conferindo,
                'from_version' => $from,
                'to_version' => $to,
                'requested_by' => $by?->id,
                'started_at' => now(),
                'step_at' => now(),
            ]);
        });
    }

    public function moveTo(UpdateStatus $status, ?string $message = null): void
    {
        $this->update([
            'status' => $status,
            'message' => $message ?? $this->message,
            'step_at' => now(),
            'finished_at' => $status->isOpen() ? null : now(),
        ]);
    }
}
