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

    /** A última restauração (ou desfazer), gravada no banco novo depois da troca. */
    public static function recordRestore(string $file, string $actor): void
    {
        Setting::put('backup.last_restore', ['at' => now()->utc()->toIso8601String(), 'file' => $file, 'actor' => $actor]);
    }

    /** @return array{at: CarbonImmutable, file: string, actor: string}|null */
    public static function lastRestore(): ?array
    {
        $value = Setting::get('backup.last_restore');
        $at = is_array($value) ? self::instantFrom($value['at'] ?? null) : null;

        if (! is_array($value) || $at === null) {
            return null;
        }

        return ['at' => $at, 'file' => is_string($value['file'] ?? null) ? $value['file'] : '', 'actor' => is_string($value['actor'] ?? null) ? $value['actor'] : ''];
    }

    /** O primeiro dia em que a checagem de atraso rodou: sem backup nenhum ainda, a carência de 26 h conta daqui. */
    public static function firstCheckAt(): ?CarbonImmutable
    {
        return self::instant('backup.first_check_at');
    }

    public static function markFirstCheck(): void
    {
        Setting::put('backup.first_check_at', now()->utc()->toIso8601String());
    }

    public static function markStaleAlerted(): void
    {
        Setting::put('backup.stale_alerted_at', now()->utc()->toIso8601String());
    }

    private static function instant(string $key): ?CarbonImmutable
    {
        return self::instantFrom(Setting::get($key));
    }

    private static function instantFrom(mixed $value): ?CarbonImmutable
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
