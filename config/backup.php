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
    | `tmp_path`: onde o dump é gerado antes de subir (os testes usam uma pasta própria por teste: rodam em paralelo).
    |
    */

    'disk' => 'backups',

    'keep' => ['diario' => 7, 'semanal' => 2, 'manual' => 3, 'antes' => 2],

    'compress' => 'zstd:3',

    'hour' => '02:15',

    'stale_hours' => 26,

    'tmp_path' => storage_path('app/tmp'),

    /*
    | Restauração pela tela de Backup:
    | `confirm_word`: o que o admin digita para confirmar; `upload_max_mb`: tamanho máximo do arquivo enviado;
    | `keep_old_days`: por quantos dias o banco anterior (`<db>_antes_...`) fica guardado para desfazer;
    | `recover_hours`: até quantas horas para trás se recuperam mensagens do WhatsApp depois da troca;
    | `staging_timeout`: limite em segundos do `pg_restore` no banco novo (o job todo cabe nos 900 s do worker `history`).
    */
    'restore' => [
        'confirm_word' => 'RESTAURAR',
        'upload_max_mb' => 100,
        'keep_old_days' => 7,
        'recover_hours' => 72,
        'staging_timeout' => 600,
    ],

];
