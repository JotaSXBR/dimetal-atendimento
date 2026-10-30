<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $shortcut
 * @property string $body
 * @property int|null $sector_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['shortcut', 'body', 'sector_id'])]
class QuickReply extends Model
{
    public const SUGGESTIONS = 6;

    /**
     * As respostas que o usuário pode usar: as de todos os setores e as do(s) setor(es) dele; supervisor e admin veem todas.
     *
     * @param  Builder<QuickReply>  $query
     */
    public function scopeForUser(Builder $query, User $user): void
    {
        if ($user->canSeeAllSectors()) {
            return;
        }

        $query->where(fn (Builder $query) => $query->whereNull('sector_id')->orWhereIn('sector_id', $user->sectors()->select('sectors.id')));
    }

    /**
     * Sugestões para um rascunho como `/pre`: atalho que começa igual, no máximo 6. Rascunho sem barra ou com espaço não casa nada.
     *
     * @param  Builder<QuickReply>  $query
     */
    public function scopeMatching(Builder $query, string $draft): void
    {
        if (preg_match('#^/([a-z0-9_-]*)$#i', $draft, $m) !== 1) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where('shortcut', 'like', strtolower($m[1]).'%')->orderBy('shortcut')->limit(self::SUGGESTIONS);
    }

    /**
     * @return BelongsTo<Sector, $this>
     */
    public function sector(): BelongsTo
    {
        return $this->belongsTo(Sector::class);
    }
}
