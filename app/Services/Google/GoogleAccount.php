<?php

namespace App\Services\Google;

use App\Enums\GoogleConnectionStatus;
use App\Models\GoogleConnection;
use Illuminate\Support\Facades\Http;
use Throwable;

/** A conta Google conectada: guarda os tokens e entrega um token de acesso válido, renovando quando preciso. */
class GoogleAccount
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** Renova com folga: um token que vence no meio de uma chamada falharia. */
    private const REFRESH_MARGIN_SECONDS = 60;

    public function current(): ?GoogleConnection
    {
        return GoogleConnection::query()->first();
    }

    public function isConnected(): bool
    {
        return $this->current()?->status === GoogleConnectionStatus::CONNECTED;
    }

    /** Grava a conta (a única linha). Sem refresh token novo, o antigo é mantido. */
    public function connect(string $email, string $accessToken, ?string $refreshToken, int $expiresIn): GoogleConnection
    {
        $connection = $this->current() ?? new GoogleConnection;

        $connection->fill([
            'account_email' => $email,
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken ?? $connection->refresh_token,
            'expires_at' => now()->addSeconds($expiresIn),
            'status' => GoogleConnectionStatus::CONNECTED,
            'last_error' => null,
            'connected_at' => now(),
        ])->save();

        return $connection;
    }

    public function disconnect(): void
    {
        GoogleConnection::query()->delete();
    }

    /** Token de acesso válido, ou `null` se não há conexão utilizável (a razão fica em `last_error`). */
    public function accessToken(): ?string
    {
        $connection = $this->current();

        if ($connection === null || $connection->status !== GoogleConnectionStatus::CONNECTED) {
            return null;
        }

        if ($connection->expires_at !== null && $connection->expires_at->gt(now()->addSeconds(self::REFRESH_MARGIN_SECONDS))) {
            return $connection->access_token;
        }

        return $this->refresh($connection);
    }

    /** O acesso foi recusado pelo Google (401/403): só uma nova autorização resolve. */
    public function markNeedsReauth(string $reason): void
    {
        $this->current()?->forceFill(['status' => GoogleConnectionStatus::NEEDS_REAUTH, 'last_error' => $reason])->save();
    }

    private function refresh(GoogleConnection $connection): ?string
    {
        if ($connection->refresh_token === null) {
            $this->markNeedsReauth('no_refresh_token');

            return null;
        }

        try {
            $response = Http::asForm()->timeout(15)->post(self::TOKEN_URL, [
                'grant_type' => 'refresh_token',
                'refresh_token' => $connection->refresh_token,
                'client_id' => config('services.google.client_id'),
                'client_secret' => config('services.google.client_secret'),
            ]);
        } catch (Throwable) {
            return $this->refreshFailed($connection);
        }

        if ($response->failed()) {
            return $response->json('error') === 'invalid_grant' || $response->status() === 401
                ? $this->revoked()
                : $this->refreshFailed($connection);
        }

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            return $this->refreshFailed($connection);
        }

        $expiresIn = $response->json('expires_in');

        $connection->forceFill([
            'access_token' => $token,
            'expires_at' => now()->addSeconds(is_int($expiresIn) ? $expiresIn : 3600),
            'last_error' => null,
        ])->save();

        return $token;
    }

    private function revoked(): null
    {
        $this->markNeedsReauth('invalid_grant');

        return null;
    }

    /** Erro passageiro (rede, 5xx): a conexão continua válida e o próximo job tenta de novo. */
    private function refreshFailed(GoogleConnection $connection): null
    {
        $connection->forceFill(['last_error' => 'token_refresh_failed'])->save();

        return null;
    }
}
