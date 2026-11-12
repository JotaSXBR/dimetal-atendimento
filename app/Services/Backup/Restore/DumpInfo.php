<?php

namespace App\Services\Backup\Restore;

use Carbon\CarbonImmutable;

/** O que o cabeçalho de um dump diz sobre ele (`pg_restore --list`), para o admin conferir antes de confirmar. */
final readonly class DumpInfo
{
    public function __construct(
        public string $name,
        public CarbonImmutable $createdAt,
        public string $database,
        public string $serverVersion,
        public string $dumpVersion,
        public string $compression,
        public int $entries,
        public int $size,
    ) {}
}
