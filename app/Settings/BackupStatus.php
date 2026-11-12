<?php

namespace App\Settings;

use App\Models\Setting;
use Carbon\CarbonImmutable;
use Throwable;

/** O último resultado do backup do banco, em `settings`: quando deu certo, o último erro e quando o atraso foi avisado. */
final class BackupStatus
{
    public static function lastSuccessAt(): ?CarbonImmutable
    {
        return self::instant('backup.last_success_at');
    }

    public static function lastError(): ?string
    {
        $error = Setting::get('backup.last_error');

        return is_string($error) && $error !== '' ? $error : null;
    }

    public static function recordSuccess(): void
    {
        Setting::put('backup.last_success_at', now()->utc()->toIso8601String());
        Setting::put('backup.last_error', null);
    }

    public static function recordFailure(string $message): void
    {
        Setting::put('backup.last_error', $message);
    }

    public static function staleAlertedAt(): ?CarbonImmutable
    {
        return self::instant('backup.stale_alerted_at');
    }

    public static function markStaleAlerted(): void
    {
        Setting::put('backup.stale_alerted_at', now()->utc()->toIso8601String());
    }

    private static function instant(string $key): ?CarbonImmutable
    {
        $value = Setting::get($key);

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
