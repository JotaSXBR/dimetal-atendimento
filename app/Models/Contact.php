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
 * @property string|null $phone2
 * @property string|null $email
 * @property string|null $company
 * @property string|null $job_title
 * @property string|null $address
 * @property string|null $notes
 * @property CarbonImmutable|null $birthday
 * @property list<string>|null $urls
 * @property CarbonImmutable|null $edited_at
 * @property string|null $google_resource_name
 * @property string|null $google_etag
 * @property string|null $google_payload_hash
 * @property CarbonImmutable|null $google_synced_at
 * @property CarbonImmutable|null $google_attempted_at
 * @property string|null $google_error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable([
    'wa_id', 'lid', 'name', 'nickname', 'phone2', 'email', 'company', 'job_title', 'address', 'notes', 'birthday', 'urls', 'edited_at',
    'google_resource_name', 'google_etag', 'google_payload_hash', 'google_synced_at', 'google_attempted_at', 'google_error',
])]
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'urls' => 'array',
            'edited_at' => 'datetime',
            'google_synced_at' => 'datetime',
            'google_attempted_at' => 'datetime',
        ];
    }

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
