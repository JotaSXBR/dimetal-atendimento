<?php

namespace App\Services\Update;

/** SemVer 2.0 (precedência); metadado de build (`+…`) é ignorado. */
final readonly class Version
{
    private const PATTERN = '/^v?(\d+)\.(\d+)\.(\d+)(?:-([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?(?:\+[0-9A-Za-z.-]+)?$/';

    /** @param  list<string>  $pre */
    private function __construct(private array $core, private array $pre) {}

    public static function parse(string $text): ?self
    {
        if (! preg_match(self::PATTERN, trim($text), $m)) {
            return null;
        }

        return new self([(int) $m[1], (int) $m[2], (int) $m[3]], isset($m[4]) ? explode('.', $m[4]) : []);
    }

    public function isRelease(): bool
    {
        return true;
    }

    public function greaterThan(self $other): bool
    {
        if ($this->core !== $other->core) {
            return $this->core > $other->core;
        }
        if (! $this->pre || ! $other->pre) {
            return ! $this->pre && $other->pre; // sem pré-release é maior
        }
        foreach ($this->pre as $i => $a) {
            $b = $other->pre[$i] ?? null;
            if ($b === null) {
                return true; // mais identificadores vence
            }
            if ($a === $b) {
                continue;
            }
            $na = ctype_digit($a);
            $nb = ctype_digit($b);

            return $na && $nb ? (int) $a > (int) $b : ($na === $nb ? $a > $b : $nb);
        }

        return false;
    }

    public function __toString(): string
    {
        return implode('.', $this->core).($this->pre ? '-'.implode('.', $this->pre) : '');
    }
}
