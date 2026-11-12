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
}
