<?php

namespace App\Models;

use App\Enums\GoogleConnectionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A conta Google que recebe os contatos (uma linha). Os tokens nunca vão para log nem para mensagem de erro.
 *
 * @property int $id
 * @property string $account_email
 * @property string $access_token
 * @property string|null $refresh_token
 * @property CarbonImmutable|null $expires_at
 * @property GoogleConnectionStatus $status
 * @property string|null $last_error
 * @property CarbonImmutable|null $connected_at
 */
#[Fillable(['account_email', 'access_token', 'refresh_token', 'expires_at', 'status', 'last_error', 'connected_at'])]
class GoogleConnection extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'expires_at' => 'datetime',
            'connected_at' => 'datetime',
            'status' => GoogleConnectionStatus::class,
        ];
    }
}
