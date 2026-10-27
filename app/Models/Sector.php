<?php

namespace App\Models;

use App\Enums\SectorStatus;
use Database\Factories\SectorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property int $menu_order
 * @property SectorStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'menu_order', 'status'])]
class Sector extends Model
{
    /** @use HasFactory<SectorFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SectorStatus::class,
        ];
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    /**
     * @param  Builder<Sector>  $query
     */
    public function scopeVisibleInMenu(Builder $query): void
    {
        $query->where('status', SectorStatus::ATIVO);
    }

    /**
     * @param  Builder<Sector>  $query
     */
    public function scopeReceivingTickets(Builder $query): void
    {
        $query->whereIn('status', [SectorStatus::ATIVO, SectorStatus::FORA_DO_MENU]);
    }
}
