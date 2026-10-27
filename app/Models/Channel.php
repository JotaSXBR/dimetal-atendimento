<?php

namespace App\Models;

use App\Enums\ChannelStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ChannelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $waha_session
 * @property string|null $phone
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
    'status',
    'last_event_at',
    'backfill_days',
    'backfill_last_run_at',
    'active',
])]
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
            'backfill_last_run_at' => 'datetime',
            'backfill_days' => 'integer',
            'active' => 'boolean',
        ];
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
