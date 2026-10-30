<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Support\AlertSettings;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property UserRole $role
 * @property bool $active
 * @property bool $can_transfer
 * @property bool $sign_messages
 * @property array<string, mixed>|null $alert_settings
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'role', 'active'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'active' => 'boolean',
            'can_transfer' => 'boolean',
            'sign_messages' => 'boolean',
            'alert_settings' => 'array',
        ];
    }

    /** Alertas em tempo real vão para o canal privado que a tela já escuta (`routes/channels.php`). */
    public function receivesBroadcastNotificationsOn(): string
    {
        return 'users.'.$this->id;
    }

    /** Cala o som do alerta crítico por 15 min ou 1 h; qualquer outro prazo é recusado. A faixa visual não depende disto. */
    public function muteCriticalFor(int $minutes): bool
    {
        if (! in_array($minutes, [15, 60], true)) {
            return false;
        }

        $settings = $this->alertSettings()->toArray();
        $settings['mute_critical_until'] = now()->addMinutes($minutes)->utc()->toIso8601String();
        $this->forceFill(['alert_settings' => $settings])->save();

        return true;
    }

    public function alertSettings(): AlertSettings
    {
        return AlertSettings::fromArray($this->alert_settings);
    }

    /**
     * @return BelongsToMany<Sector, $this>
     */
    public function sectors(): BelongsToMany
    {
        return $this->belongsToMany(Sector::class);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::ADMIN;
    }

    /** Admin ativo que é o único: desativá-lo ou rebaixá-lo trancaria a administração do sistema. */
    public function isLastActiveAdmin(): bool
    {
        return $this->isAdmin()
            && $this->active
            && ! static::query()->where('role', UserRole::ADMIN->value)->where('active', true)->whereKeyNot($this->id)->exists();
    }

    /**
     * @return HasMany<Ticket, $this>
     */
    public function assignedTickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'assigned_user_id');
    }

    public function canSeeAllSectors(): bool
    {
        return $this->role !== UserRole::ATENDENTE;
    }

    /**
     * Pode atender tickets desse setor: ticket sem setor é de todos; supervisor e admin atendem qualquer um.
     */
    public function canHandleSector(?int $sectorId): bool
    {
        return $sectorId === null || $this->canSeeAllSectors() || $this->sectors()->whereKey($sectorId)->exists();
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
