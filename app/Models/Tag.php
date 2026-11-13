<?php

namespace App\Models;

use App\Enums\TagColor;
use App\Enums\TagScope;
use Database\Factories\TagFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $name
 * @property TagScope $scope
 * @property TagColor $color
 */
#[Fillable(['name', 'scope', 'color'])]
class Tag extends Model
{
    /** @use HasFactory<TagFactory> */
    use HasFactory;

    /**
     * As etiquetas em ordem alfabética sem diferenciar acento nem caixa (o banco ordena pela collation dele, que muda de
     * instalação para instalação: "Ágil" cairia depois de "Zebra" numa collation C).
     *
     * @param  Collection<int, Tag>  $tags
     * @return Collection<int, Tag>
     */
    public static function sortedByName(Collection $tags): Collection
    {
        return $tags->sortBy(fn (Tag $tag): string => Str::ascii(mb_strtolower($tag->name)), SORT_STRING)->values();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['scope' => TagScope::class, 'color' => TagColor::class];
    }

    /** @param  Builder<Tag>  $query */
    public function scopeOfContact(Builder $query): void
    {
        $query->where('scope', TagScope::CONTATO->value);
    }

    /** @param  Builder<Tag>  $query */
    public function scopeOfTicket(Builder $query): void
    {
        $query->where('scope', TagScope::CONVERSA->value);
    }

    /** @return BelongsToMany<Contact, $this> */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'contact_tag');
    }

    /** @return BelongsToMany<Ticket, $this> */
    public function tickets(): BelongsToMany
    {
        return $this->belongsToMany(Ticket::class, 'ticket_tag');
    }
}
