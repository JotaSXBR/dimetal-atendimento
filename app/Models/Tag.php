<?php

namespace App\Models;

use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property string $color
 */
#[Fillable(['name', 'color'])]
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    /**
     * As etiquetas em ordem alfabética sem diferenciar acento nem caixa (o banco ordena pela collation dele, que muda de
     * instalação para instalação: "Ágil" cairia depois de "Zebra" numa collation C).
     *
     * @param  \Illuminate\Support\Collection<int, Tag>  $tags
     * @return \Illuminate\Support\Collection<int, Tag>
     */
    public static function sortedByName(\Illuminate\Support\Collection $tags): \Illuminate\Support\Collection
    {
        return $tags->sortBy(fn (Tag $tag): string => Str::ascii(mb_strtolower($tag->name)), SORT_STRING)->values();
    }

    /** @return BelongsToMany<Ticket, $this> */
    public function tickets(): BelongsToMany
    {
        return $this->belongsToMany(Ticket::class, 'ticket_tag');
    }
}
