<?php

namespace App\Models;

use App\Enums\ChannelStatus;
use App\Observers\ChannelObserver;
use App\Support\PhoneFormat;
use Carbon\CarbonImmutable;
use Database\Factories\ChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $waha_session
 * @property string|null $phone
 * @property string|null $previous_phone
 * @property CarbonImmutable|null $phone_changed_at
 * @property ChannelStatus $status
 * @property CarbonImmutable|null $last_event_at
 * @property int $backfill_days
 * @property CarbonImmutable|null $backfill_last_run_at
 * @property bool $active
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'name',
    'waha_session',
    'phone',
    'previous_phone',
    'phone_changed_at',
    'status',
    'last_event_at',
    'backfill_days',
    'backfill_last_run_at',
    'active',
])]
#[ObservedBy(ChannelObserver::class)]
class Channel extends Model
{
    /** @use HasFactory<ChannelFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ChannelStatus::class,
            'last_event_at' => 'datetime',
            'phone_changed_at' => 'datetime',
            'backfill_last_run_at' => 'datetime',
            'backfill_days' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** O número como o resto do app mostra, ou `null` enquanto o WAHA não o informou. */
    public function phoneLabel(): ?string
    {
        return $this->phone === null ? null : PhoneFormat::display($this->phone);
    }

    public function isConnected(): bool
    {
        return $this->status === ChannelStatus::WORKING;
    }

    public function normalizeChatId(string $phone): string
    {
        return preg_replace('/\D/', '', $phone).'@c.us';
    }
}
