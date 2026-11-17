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
        /** A versão do sistema em que foi feito; `null` nos nomes antigos e nos de desenvolvimento. */
        public ?string $version = null,
    ) {}
}
