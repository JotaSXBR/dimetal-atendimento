<?php

namespace App\Services\Update;

/** SemVer 2.0 (precedência); metadado de build (`+…`) é ignorado. */
final readonly class Version
{
    private const PATTERN = '/^v?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*))?(?:\+[0-9A-Za-z.-]+)?$/';

    /**
     * @param  list<int>  $core
     * @param  list<string>  $pre
     */
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
            if ($a !== $b) {
                return self::compareIdentifier($a, $b) > 0;
            }
        }

        return false;
    }

    /** Números sem estouro de int (mais longo vence, igual compara por texto); número antes de texto; texto por strcmp. */
    private static function compareIdentifier(string $a, string $b): int
    {
        $na = ctype_digit($a);
        $nb = ctype_digit($b);
        if ($na && $nb) {
            return strlen($a) <=> strlen($b) ?: strcmp($a, $b);
        }

        return $na === $nb ? strcmp($a, $b) : ($nb ? 1 : -1);
    }

    public function __toString(): string
    {
        return implode('.', $this->core).($this->pre ? '-'.implode('.', $this->pre) : '');
    }
}
