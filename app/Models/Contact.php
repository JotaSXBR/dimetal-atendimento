<?php

namespace App\Models;

use App\Support\PhoneFormat;
use Carbon\CarbonImmutable;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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

    protected static function booted(): void
    {
        // `updated_at` tem resolução de 1 s. Uma edição no mesmo segundo do último envio ao Google ficaria igual a ele e a
        // varredura não a veria; então a edição fica sempre ao menos 1 s à frente. O próprio envio grava os dois juntos.
        static::updating(function (Contact $contact): void {
            $synced = $contact->google_synced_at;

            if ($synced !== null && ! $contact->isDirty(['google_synced_at', 'updated_at']) && ! now()->startOfSecond()->gt($synced)) {
                $contact->updated_at = $synced->addSecond();
            }
        });
    }

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

    /**
     * Busca por nome, apelido, e-mail, empresa e, se o termo parece telefone, pelos dígitos do `wa_id`.
     * `%` e `_` digitados valem como texto, não como curinga.
     *
     * @param  Builder<Contact>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';
        $digits = preg_match('/^[\d\s()+-]+$/', $term) === 1 ? (string) preg_replace('/\D/', '', $term) : '';

        $query->where(function (Builder $query) use ($like, $digits): void {
            $query->whereRaw('name ilike ?', [$like])
                ->orWhereRaw('nickname ilike ?', [$like])
                ->orWhereRaw('email ilike ?', [$like])
                ->orWhereRaw('company ilike ?', [$like]);

            if (strlen($digits) >= 3) {
                $query->orWhereRaw('wa_id like ?', ['%'.$digits.'%']);
            }
        });
    }

    /**
     * Editado, com como ser achado no Google (telefone do WhatsApp ou e-mail) e ainda não enviado, ou alterado depois do último
     * envio. O envio grava `google_synced_at` e `updated_at` no mesmo instante; qualquer edição posterior desfaz a igualdade.
     *
     * @param  Builder<Contact>  $query
     */
    public function scopePendingGoogle(Builder $query): void
    {
        $query->whereNotNull('edited_at')
            ->where(fn (Builder $query) => $query->where('wa_id', 'like', '%@c.us')->orWhereNotNull('email'))
            ->where(fn (Builder $query) => $query->whereNull('google_synced_at')->orWhereColumn('updated_at', '>', 'google_synced_at'));
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
