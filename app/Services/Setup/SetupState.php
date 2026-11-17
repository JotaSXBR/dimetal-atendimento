<?php

namespace App\Services\Setup;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/** Estado do primeiro acesso: sem admin, em configuração (admin e sem marca) ou concluída. */
final class SetupState
{
    public const CONCLUDED = 'setup.concluido';

    public const STEP = 'setup.passo';

    public const SEGMENT = 'setup.segmento';

    public const STEPS = ['empresa', 'segmento', 'canal'];

    /** Só o `true` fica no cache: a instalação concluída não volta atrás, e o `false` precisa ser relido do banco. */
    public static function concluded(): bool
    {
        if (Cache::get(self::CONCLUDED) === true) {
            return true;
        }

        $concluded = Setting::get(self::CONCLUDED) === true;
        if ($concluded) {
            Cache::forever(self::CONCLUDED, true);
        }

        return $concluded;
    }

    public static function markConcluded(): void
    {
        Setting::put(self::CONCLUDED, true, 'bool');
        Cache::forever(self::CONCLUDED, true);
    }

    public static function hasAdmin(): bool
    {
        return User::query()->where('role', UserRole::ADMIN->value)->exists();
    }

    public static function step(): string
    {
        $step = Setting::get(self::STEP);

        return is_string($step) && in_array($step, self::STEPS, true) ? $step : self::STEPS[0];
    }

    public static function setStep(string $step): void
    {
        if (! in_array($step, self::STEPS, true)) {
            throw new InvalidArgumentException("Passo [{$step}] inválido.");
        }

        Setting::put(self::STEP, $step);
    }

    public static function segment(): ?string
    {
        $segment = Setting::get(self::SEGMENT);

        return is_string($segment) ? $segment : null;
    }

    public static function setSegment(string $segment): void
    {
        Setting::put(self::SEGMENT, $segment);
    }
}
