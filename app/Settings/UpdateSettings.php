<?php

namespace App\Settings;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Throwable;

/** O que a atualização pelo app guarda em `settings`: consulta às Releases, última versão vista, URL do painel e o token do Coolify (cifrado). */
final class UpdateSettings
{
    public static function checkEnabled(): bool
    {
        return (bool) Setting::get('update.check_enabled', true);
    }

    public static function setCheckEnabled(bool $enabled): void
    {
        Setting::put('update.check_enabled', $enabled);
    }

    /** @return array{at: CarbonImmutable, ok: bool, error: ?string}|null */
    public static function lastCheck(): ?array
    {
        $value = Setting::get('update.last_check');
        $at = is_array($value) ? self::instant($value['at'] ?? null) : null;

        if (! is_array($value) || $at === null) {
            return null;
        }

        return ['at' => $at, 'ok' => ($value['ok'] ?? false) === true, 'error' => is_string($value['error'] ?? null) ? $value['error'] : null];
    }

    public static function recordCheck(bool $ok, ?string $error = null): void
    {
        Setting::put('update.last_check', ['at' => now()->utc()->toIso8601String(), 'ok' => $ok, 'error' => $error]);
    }

    /** @return array{tag: string, published_at: string, notes_url: string, compose_url: string}|null */
    public static function latest(): ?array
    {
        $value = Setting::get('update.latest');

        if (! is_array($value)) {
            return null;
        }

        $tag = $value['tag'] ?? null;
        $compose = $value['compose_url'] ?? null;

        if (! is_string($tag) || ! is_string($compose)) {
            return null;
        }

        return [
            'tag' => $tag,
            'published_at' => is_string($value['published_at'] ?? null) ? $value['published_at'] : '',
            'notes_url' => is_string($value['notes_url'] ?? null) ? $value['notes_url'] : '',
            'compose_url' => $compose,
        ];
    }

    /** @param  array{tag: string, published_at: string, notes_url: string, compose_url: string}  $latest */
    public static function setLatest(array $latest): void
    {
        Setting::put('update.latest', $latest);
    }

    public static function notifiedVersion(): ?string
    {
        return self::text('update.notified_version');
    }

    public static function markNotified(string $version): void
    {
        Setting::put('update.notified_version', $version);
    }

    public static function panelUrl(): ?string
    {
        return self::text('update.panel_url');
    }

    public static function setPanelUrl(?string $url): void
    {
        Setting::put('update.panel_url', $url);
    }

    public static function token(): ?string
    {
        try {
            $token = Setting::get('update.token');
        } catch (DecryptException) {
            // APP_KEY trocada: o token guardado não abre mais; a tela pede para colar de novo (a exceção não leva o valor).
            return null;
        }

        return is_string($token) && $token !== '' ? $token : null;
    }

    /** `null` remove o token (a linha some; `put` com `secret` e `null` gravaria o texto "null" cifrado). */
    public static function setToken(?string $token): void
    {
        if ($token === null || $token === '') {
            Setting::query()->whereIn('key', ['update.token', 'update.token_saved_at'])->delete();

            return;
        }

        Setting::put('update.token', $token, 'secret');
        Setting::put('update.token_saved_at', now()->utc()->toIso8601String());
    }

    public static function tokenSavedAt(): ?CarbonImmutable
    {
        return self::instant(Setting::get('update.token_saved_at'));
    }

    private static function text(string $key): ?string
    {
        $value = Setting::get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function instant(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
