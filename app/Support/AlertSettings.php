<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Throwable;

/** As preferências de alerta de um usuário: nunca lança; nulo, chave faltando ou lixo caem nos padrões. */
final readonly class AlertSettings
{
    public const DEFAULT_VOLUME = 70;

    public function __construct(
        public bool $soundMessage = true,
        public bool $soundQueue = true,
        public bool $soundAssigned = true,
        public bool $soundSla = true,
        public int $volume = self::DEFAULT_VOLUME,
        public bool $repeat = true,
        public bool $desktopNotifications = false,
        public ?CarbonImmutable $muteCriticalUntil = null,
    ) {}

    /** @param  array<string, mixed>|null  $raw */
    public static function fromArray(?array $raw): self
    {
        $raw ??= [];
        $sound = is_array($raw['sound'] ?? null) ? $raw['sound'] : [];
        $d = new self;

        return new self(
            soundMessage: self::flag($sound['message'] ?? null, $d->soundMessage),
            soundQueue: self::flag($sound['queue'] ?? null, $d->soundQueue),
            soundAssigned: self::flag($sound['assigned'] ?? null, $d->soundAssigned),
            soundSla: self::flag($sound['sla'] ?? null, $d->soundSla),
            volume: is_numeric($raw['volume'] ?? null) ? max(0, min(100, (int) $raw['volume'])) : self::DEFAULT_VOLUME,
            repeat: self::flag($raw['repeat'] ?? null, $d->repeat),
            desktopNotifications: self::flag($raw['desktop_notifications'] ?? null, $d->desktopNotifications),
            muteCriticalUntil: self::instant($raw['mute_critical_until'] ?? null),
        );
    }

    public function criticalMuted(): bool
    {
        return $this->muteCriticalUntil !== null && $this->muteCriticalUntil->isFuture();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'sound' => ['message' => $this->soundMessage, 'queue' => $this->soundQueue, 'assigned' => $this->soundAssigned, 'sla' => $this->soundSla],
            'volume' => $this->volume,
            'repeat' => $this->repeat,
            'desktop_notifications' => $this->desktopNotifications,
            'mute_critical_until' => $this->muteCriticalUntil?->utc()->toIso8601String(),
        ];
    }

    private static function flag(mixed $value, bool $default): bool
    {
        return is_bool($value) ? $value : $default;
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
