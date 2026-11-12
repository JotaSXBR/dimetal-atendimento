<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backup diário do banco
    |--------------------------------------------------------------------------
    |
    | `disk`: onde os dumps ficam (config/filesystems.php; S3/RustFS com BACKUP_BUCKET, senão pasta local).
    | `keep`: quantos arquivos de cada tipo ficam depois de um backup novo gravado.
    | `compress`: método do `pg_dump --compress` (cliente 18).
    | `hour`: horário do backup diário (antes da purga de mídia das 03:30).
    | `stale_hours`: sem backup bom há mais que isso, os admins são avisados.
    |
    */

    'disk' => 'backups',

    'keep' => ['diario' => 7, 'semanal' => 2, 'manual' => 3],

    'compress' => 'zstd:3',

    'hour' => '02:15',

    'stale_hours' => 26,

];
