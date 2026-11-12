<?php

namespace App\Services\Backup;

use Carbon\CarbonImmutable;

/** Um arquivo de backup no bucket: o tipo e a data saem do nome (ver {@see BackupCatalog::name()}). */
final readonly class BackupFile
{
    public function __construct(
        public string $name,
        public BackupKind $kind,
        public CarbonImmutable $createdAt,
        public int $size,
    ) {}
}
