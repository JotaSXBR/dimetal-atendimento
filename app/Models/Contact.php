<?php

namespace App\Models;

use App\Support\PhoneFormat;
use Carbon\CarbonImmutable;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string|null $wa_id
 * @property string|null $lid
 * @property string|null $name
 * @property string|null $nickname
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['wa_id', 'lid', 'name', 'nickname'])]
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    /** Nome do contato ou, sem nome, o telefone em formato humano. */
    public function label(): string
    {
        $name = trim((string) $this->name);

        return $name !== '' ? $name : $this->phoneLabel();
    }

    public function phoneLabel(): string
    {
        return PhoneFormat::display($this->wa_id);
    }

    /**
     * @return HasMany<Ticket, $this>
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
