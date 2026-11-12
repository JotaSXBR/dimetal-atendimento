<?php

namespace App\Services\Backup\Restore;

use Carbon\CarbonImmutable;

/** Um banco anterior guardado depois de uma restauração (`<db>_antes_AAAAMMDDHHMM`), para voltar a ele. */
final readonly class StoredDatabase
{
    public function __construct(
        public string $name,
        public CarbonImmutable $createdAt,
        public int $bytes,
    ) {}

    /**
     * Uma linha de `pg_database` (`datname`, `bytes`) vira um banco guardado, ou `null` se o nome não é `<ativo>_<tipo>_AAAAMMDDHHMM`.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromRow(array $row, string $active, string $kind, string $zone): ?self
    {
        $name = is_string($row['datname'] ?? null) ? $row['datname'] : '';

        if (preg_match('/^'.preg_quote($active, '/').'_'.$kind.'_(\\d{12})$/', $name, $m) !== 1) {
            return null;
        }

        $at = CarbonImmutable::createFromFormat('YmdHi', $m[1], $zone);

        return $at === null ? null : new self($name, $at, is_numeric($row['bytes'] ?? null) ? (int) $row['bytes'] : 0);
    }
}
